<?php

namespace App\Services\Server\Applications;

use App\Contracts\WebServerDriver;
use App\Enums\ApplicationStatus;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\WebServers\AbstractWebServerDriver;
use App\Services\Server\WebServers\OlsVhostLayout;
use App\Services\Server\WebServers\WebServerManager;

/**
 * Re-render every live site's vhost from the current templates and shipped lists.
 *
 * The bot list, the 8G ruleset and the templates ship inside the panel, so an update changes what
 * a site's config would say without changing what it does say; this closes that gap.
 *
 * Safety rules (this touches every site at once):
 *
 *  - the previous file is read first, so a site can be restored byte-for-byte;
 *  - the config is tested after every site (the test is server-wide, so a batch hides which one broke);
 *  - a failing site is restored and the run continues;
 *  - nothing is reloaded unless something changed (config text, or a group grant that needs a
 *    restart) and the config tests clean at the end.
 */
class SiteConfigResyncer
{
    public function __construct(
        private WebServerManager $webServers,
        private ApplicationProvisioner $provisioner,
        private ManagedFile $files,
        private ServerOps $serverOps,
        private BasicAuthManager $basicAuth,
        private InstallerManager $installers,
    ) {}

    /**
     * @return array{total: int, updated: int, unchanged: int, granted: int, failed: array<int, array{id: int, name: string, reference: ?string}>, reloaded: bool}
     */
    public function run(): array
    {
        $driver = $this->webServers->driver();

        // Detect the OpenLiteSpeed layout before any vhost is written; an imported v7 server may
        // never have been detected and its sites would land where OLS does not look.
        if ($driver->name() === 'openlitespeed') {
            app(OlsVhostLayout::class)->detect();
        }

        $applications = Application::query()
            ->where('status', ApplicationStatus::Active)
            // A disabled site's vhost points at the disabled page; re-rendering would bring it back online.
            ->whereNull('disabled_at')
            ->with(['systemUser', 'certificate', 'botRules', 'wafRules'])
            ->get();

        $updated = 0;
        $unchanged = 0;
        $granted = 0;
        $failed = [];

        foreach ($applications as $application) {
            try {
                // The app config is rendered state too: certificates and primary domains can change
                // its canonical URL after install.
                $this->installers->syncUrl($application);
            } catch (ProvisioningFailedException $exception) {
                $failed[] = $this->failure($application, $exception->reference);

                continue;
            }

            // Older sites still have a `{domain}.conf`; leaving it beside the new file loads two
            // server blocks for the same names.
            $this->removeLegacyConfig($driver, $application);

            // Before the vhost is rendered, never after: the credential file moved above the
            // document root, and a vhost pointing at a missing file fails closed (500 for everyone).
            // Rewritten from the stored hash.
            $this->basicAuth->publish($application);

            // Every file the site has: with a certificate nginx/Apache use two (as in v7). A null
            // entry is a file that must no longer exist.
            $files = $driver->configFiles($application, $this->provisioner->documentRoot($application));
            $previous = [];

            foreach (array_keys($files) as $path) {
                $previous[$path] = $this->read($path);
            }

            // Create the directories the config names. This class skips `apply()` so it can compare
            // output, so it must do this itself, and before the unchanged check: the sites it repairs
            // already have correct config text (on OLS a missing `location` fails the config test).
            // Cheap: no-ops when the directories exist.
            // Returns true when the web server's account was added to the site's log group; that only
            // takes effect on restart, so an unchanged site still needs one.
            if ($driver->ensureDirectories($application)) {
                $granted++;
            }

            // A conf-folder file that is v7's or the owner's (no panel marker) is left exactly as is.
            foreach (array_keys($files) as $path) {
                if ($driver->isSiteConfPath($application, $path)
                    && $previous[$path] !== null
                    && ! AbstractWebServerDriver::siteConfIsOurs($previous[$path])) {
                    unset($files[$path], $previous[$path]);
                }
            }

            $changed = array_filter(
                $files,
                fn (?string $contents, string $path) => $contents === null
                    ? $previous[$path] !== null
                    : $previous[$path] === null || $this->normalize($previous[$path]) !== $this->normalize($contents),
                ARRAY_FILTER_USE_BOTH,
            );

            if ($changed === []) {
                $unchanged++;

                continue;
            }

            $written = $this->publish($driver, $application, $changed);

            if ($written->failed()) {
                $this->rollback($driver, $application, $changed, $previous);

                $failed[] = $this->failure($application, $written->reference);

                continue;
            }

            $test = $driver->test();

            if ($test->failed()) {
                $this->rollback($driver, $application, $changed, $previous);

                $failed[] = $this->failure($application, $test->reference);

                continue;
            }

            $updated++;
        }

        // One reload for the whole batch; each site was already tested.
        $reloaded = false;

        // `$granted` too: otherwise the web server keeps running without the new group.
        if (($updated > 0 || $granted > 0) && $driver->test()->ok) {
            $driver->reload();
            $reloaded = true;
        }

        return [
            'total' => $applications->count(),
            'updated' => $updated,
            'unchanged' => $unchanged,
            'granted' => $granted,
            'failed' => $failed,
            'reloaded' => $reloaded,
        ];
    }

    /**
     * Write (and link) or remove each changed file.
     *
     * @param  array<string, ?string>  $changed
     */
    private function publish(WebServerDriver $driver, Application $application, array $changed): ServerOpsResult
    {
        $result = new ServerOpsResult(true, 'resync-nothing-written');

        foreach ($changed as $path => $contents) {
            if ($contents === null) {
                if (! $driver->isSiteConfPath($application, $path)) {
                    $this->files->delete($driver->enabledPathFor($path), $this->context($application, 'resync_remove'));
                }
                $result = $this->files->delete($path, $this->context($application, 'resync_remove'));
            } else {
                $result = $this->files->put($path, $contents, $this->context($application, 'resync_write'));

                if ($result->ok) {
                    $result = $driver->link($path, $this->context($application, 'resync_link'));
                }
            }

            if ($result->failed()) {
                return $result;
            }
        }

        return $result;
    }

    /**
     * Put every changed file back as it was.
     *
     * @param  array<string, ?string>  $changed
     * @param  array<string, ?string>  $previous
     */
    private function rollback(WebServerDriver $driver, Application $application, array $changed, array $previous): void
    {
        if (array_filter($previous, fn (?string $contents) => $contents !== null) === []) {
            // No file existed before; remove via the driver, since a shared file may reference the site.
            $driver->remove($application);

            return;
        }

        foreach (array_keys($changed) as $path) {
            if ($previous[$path] === null) {
                if (! $driver->isSiteConfPath($application, $path)) {
                    $this->files->delete($driver->enabledPathFor($path), $this->context($application, 'resync_rollback'));
                }
                $this->files->delete($path, $this->context($application, 'resync_rollback'));

                continue;
            }

            $this->files->put($path, $previous[$path], $this->context($application, 'resync_rollback'));
            $driver->link($path, $this->context($application, 'resync_rollback'));
        }
    }

    /**
     * Remove the config this site would have had under the old domain-based naming.
     *
     * Deliberately narrow: only the one path this application owned, never a sweep (it would delete
     * hand-written vhosts). Uses the driver's remove() so the sites-enabled symlink goes too; a
     * dangling symlink fails the web server's config test.
     */
    private function removeLegacyConfig(WebServerDriver $driver, Application $application): void
    {
        if (blank($application->slug) || blank($application->domain)) {
            return;
        }

        // Ask the driver for the slug-less path rather than assuming nginx's layout.
        $legacyApplication = (clone $application)->forceFill(['slug' => null]);
        $legacy = $driver->configPath($legacyApplication);

        if ($legacy === $driver->configPath($application)) {
            return;
        }

        $driver->remove($legacyApplication);
    }

    private function read(string $path): ?string
    {
        $result = $this->serverOps->run(
            ['cat', $path],
            ['feature' => 'application', 'op' => 'resync_read'],
            timeout: 30,
        );

        return $result->failed() ? null : $result->output();
    }

    /**
     * Trailing-whitespace differences are not drift.
     */
    private function normalize(string $config): string
    {
        return rtrim(str_replace("\r\n", "\n", $config));
    }

    /**
     * @return array{id: int, name: string, reference: ?string}
     */
    private function failure(Application $application, ?string $reference): array
    {
        return [
            'id' => $application->id,
            'name' => $application->name,
            'reference' => $reference,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Application $application, string $op): array
    {
        return ['feature' => 'application', 'op' => $op, 'application' => $application->id];
    }
}
