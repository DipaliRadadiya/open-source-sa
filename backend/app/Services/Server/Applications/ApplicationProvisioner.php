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
        private InstallerManager $installers,
        private ProcessSupervisor $supervisor,
        private ProvisionProgress $progress,
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
        $path = $webRoot === '' ? $base : "{$base}/{$webRoot}";

        // Checked here as well as in validation, because this string is handed
        // straight to `mkdir -p`, `chown -R` and `tee` as root. A `..` segment
        // in web_root would walk out of the site and hand, say, /etc to the
        // site's user. Validation is the fix; this is the belt to its braces,
        // and the place the damage would actually happen.
        abort_if(
            str_contains($path, '/../') || str_ends_with($path, '/..'),
            500,
        );

        return $path;
    }

    /**
     * Steps are recorded on the application as each one completes, not
     * collected and written at the end — the user is watching this happen, and
     * a failure halfway should leave behind how far it got.
     *
     * @return array<int, string> the steps completed, in order
     *
     * @throws ProvisioningFailedException
     */
    public function provision(Application $application): array
    {
        $driver = $this->webServers->driver();
        $user = $application->systemUser;
        $documentRoot = $this->documentRoot($application);

        $this->progress->open($application);

        $this->step('create_directory', fn () => $this->serverOps->run(
            ['mkdir', '-p', $documentRoot],
            ['feature' => 'application', 'op' => 'mkdir', 'application' => $application->id],
        ));

        $this->step('set_ownership', fn () => $this->serverOps->run(
            ['chown', '-R', "{$user->username}:{$user->username}", $documentRoot],
            ['feature' => 'application', 'op' => 'chown', 'application' => $application->id],
        ));

        $this->step('placeholder', fn () => $this->serverOps->run(
            ['tee', $this->placeholderPath($application, $documentRoot)],
            ['feature' => 'application', 'op' => 'placeholder', 'application' => $application->id],
            input: $this->placeholderContents($application),
        ));

        $this->step('write_config', fn () => $driver->apply($application, $documentRoot));

        // Test before reload — and if the test fails, take our config back out
        // so the next reload (ours or anyone else's) is not poisoned by it.
        $test = $driver->test();

        if ($test->failed()) {
            // The driver's own removal, not `rm -f`: on a web server whose
            // site lives partly in a shared file, deleting the per-site file
            // would leave the shared one pointing at something gone — a config
            // that is still broken, and now broken in a harder way.
            $driver->remove($application);

            throw new ProvisioningFailedException('test_config', $test->reference);
        }

        $this->progress->record('test_config');

        $this->step('reload', fn () => $driver->reload());

        // Marketplace apps install once the site is actually being served —
        // WordPress writes its own URL into the database during setup, so it
        // needs the vhost live first. Site types with no installer (git,
        // blank PHP, static) record nothing here.
        $this->installers->install($application, $documentRoot);

        // The process last, because until the installer has run there is
        // nothing to start. Starting first was wrong in both directions: a
        // one-click Node app would be launched against an empty directory,
        // and a git application has no code at all until its first deploy —
        // `systemctl start` succeeds, the process dies immediately, and
        // provisioning fails on a site that is otherwise fine.
        $this->startProcess($application, $documentRoot);

        return $this->progress->steps();
    }

    /**
     * Start the application's process, if there is one and there is anything
     * for it to run yet.
     *
     * A one-click application's start command is written here rather than
     * asked for: the installer knows the one right answer, and it needs the
     * document root to say it.
     *
     * A git application reaches this with a start command and an empty
     * directory. Its unit is written and enabled — so the port, the limits and
     * the boot behaviour are all in place — but it is not started, because the
     * code arrives with the first deploy, which starts it.
     *
     * @throws ProvisioningFailedException
     */
    private function startProcess(Application $application, string $documentRoot): void
    {
        $installer = $this->installers->installerFor($application);
        $command = $installer?->startCommand($application, $documentRoot);

        if ($command !== null) {
            $application->forceFill(['start_command' => $command])->save();
        }

        if (! $this->supervisor->runs($application)) {
            return;
        }

        // Installed applications have their code now; a git checkout does not.
        $ready = $command !== null;

        $this->supervisor->apply($application, $documentRoot, start: $ready);

        $this->progress->record($ready ? 'start_app' : 'write_unit');
    }

    /**
     * Remove the site's config and reload, so the domain stops being served.
     * Files are only deleted when explicitly asked for — losing a user's code
     * must never be a side effect of removing a panel record.
     */
    public function deprovision(Application $application, bool $removeFiles = false): void
    {
        // The process first: a unit left running holds its port and keeps
        // serving traffic for a site the panel has stopped listing.
        $this->supervisor->remove($application);

        $driver = $this->webServers->driver();

        $driver->remove($application);

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

        $this->progress->record($name);
    }
}
