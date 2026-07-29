<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\WebServers\WebServerManager;

/**
 * Puts an application on the server: directory, ownership, site config, and a
 * tested reload.
 *
 * Every step is idempotent (`mkdir -p`, overwrite the config, `rm -f`), so a
 * re-run after a partial failure converges rather than compounding.
 *
 * The rule that matters: **the config is tested before anything is reloaded**,
 * and a failed test removes the config we just wrote. A broken vhost that
 * reaches a reload takes every other site on the box down with it — one user's
 * typo must never cost the whole server.
 */
class ApplicationProvisioner
{
    public function __construct(
        private ServerOps $serverOps,
        private WebServerManager $webServers,
    ) {}

    /**
     * The site's directory: the owning System User's home plus the domain.
     *
     * Built from stored, validated values — the domain is constrained to a
     * hostname charset at validation, so no client string can introduce a
     * path separator or a `..` segment here.
     */
    public function documentRoot(Application $application): string
    {
        $home = rtrim((string) $application->systemUser->home_path, '/');
        $webRoot = trim((string) ($application->web_root ?: '/'), '/');

        $base = "{$home}/{$application->domain}";

        return $webRoot === '' ? $base : "{$base}/{$webRoot}";
    }

    /**
     * @return array<int, string> the steps completed, in order
     *
     * @throws ProvisioningFailedException
     */
    public function provision(Application $application): array
    {
        $driver = $this->webServers->driver();
        $user = $application->systemUser;
        $documentRoot = $this->documentRoot($application);
        $configPath = $driver->configPath($application);
        $completed = [];

        $this->step('create_directory', fn () => $this->serverOps->run(
            ['mkdir', '-p', $documentRoot],
            ['feature' => 'application', 'op' => 'mkdir', 'application' => $application->id],
        ));
        $completed[] = 'create_directory';

        $this->step('set_ownership', fn () => $this->serverOps->run(
            ['chown', '-R', "{$user->username}:{$user->username}", $documentRoot],
            ['feature' => 'application', 'op' => 'chown', 'application' => $application->id],
        ));
        $completed[] = 'set_ownership';

        $this->step('placeholder', fn () => $this->serverOps->run(
            ['tee', $this->placeholderPath($application, $documentRoot)],
            ['feature' => 'application', 'op' => 'placeholder', 'application' => $application->id],
            input: $this->placeholderContents($application),
        ));
        $completed[] = 'placeholder';

        $this->step('write_config', fn () => $this->serverOps->run(
            ['tee', $configPath],
            ['feature' => 'application', 'op' => 'write_config', 'application' => $application->id],
            input: $driver->renderConfig($application, $documentRoot),
        ));
        $completed[] = 'write_config';

        // Test before reload — and if the test fails, take our config back out
        // so the next reload (ours or anyone else's) is not poisoned by it.
        $test = $driver->test();

        if ($test->failed()) {
            $this->serverOps->run(
                ['rm', '-f', $configPath],
                ['feature' => 'application', 'op' => 'rollback_config', 'application' => $application->id],
            );

            throw new ProvisioningFailedException('test_config', $test->reference);
        }

        $completed[] = 'test_config';

        $this->step('reload', fn () => $driver->reload());
        $completed[] = 'reload';

        return $completed;
    }

    /**
     * Remove the site's config and reload, so the domain stops being served.
     * Files are only deleted when explicitly asked for — losing a user's code
     * must never be a side effect of removing a panel record.
     */
    public function deprovision(Application $application, bool $removeFiles = false): void
    {
        $driver = $this->webServers->driver();

        $this->serverOps->run(
            ['rm', '-f', $driver->configPath($application)],
            ['feature' => 'application', 'op' => 'remove_config', 'application' => $application->id],
        );

        if ($removeFiles) {
            $this->serverOps->run(
                ['rm', '-rf', "{$application->systemUser->home_path}/{$application->domain}"],
                ['feature' => 'application', 'op' => 'remove_files', 'application' => $application->id],
            );
        }

        // A failed test here means the server config was already broken before
        // us; reloading anyway would make that worse, so skip it.
        if ($driver->test()->ok) {
            $driver->reload();
        }
    }

    private function placeholderPath(Application $application, string $documentRoot): string
    {
        return $documentRoot.'/'.($application->serving_profile === 'php' ? 'index.php' : 'index.html');
    }

    private function placeholderContents(Application $application): string
    {
        $domain = e($application->domain);

        $body = "<!doctype html><meta charset=\"utf-8\"><title>{$domain}</title>"
            ."<h1>{$domain}</h1><p>This site is ready. Upload your files or deploy your code.</p>";

        return $application->serving_profile === 'php'
            ? "<?php // Placeholder written by the panel — replace with your app.\n?>\n".$body."\n"
            : $body."\n";
    }

    /**
     * @param  callable(): ServerOpsResult  $operation
     *
     * @throws ProvisioningFailedException
     */
    private function step(string $name, callable $operation): void
    {
        $result = $operation();

        if ($result->failed()) {
            throw new ProvisioningFailedException($name, $result->reference);
        }
    }
}
