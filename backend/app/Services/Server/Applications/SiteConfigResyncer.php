<?php

namespace App\Services\Server\Applications;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\WebServers\WebServerManager;

/**
 * Re-render every live site's vhost from the current templates and shipped
 * lists.
 *
 * Why this exists: a vhost is a *rendered file*. The AI bot list, the 8G
 * ruleset and the templates themselves all ship inside the panel, so a panel
 * update changes what a site's config *would* say without changing what it
 * does say. Until now nothing closed that gap — the panel would report "31
 * bots blocked" from the new list while the site on disk still blocked the
 * old set, and neither side could detect the difference.
 *
 * That is worse than a missing feature. Protection the user believes is on,
 * silently running a version behind, is a security control that has quietly
 * stopped being one. Drift the user has to notice by hand is not a design.
 *
 * The safety rules, which matter more here than anywhere else because this
 * touches every site at once:
 *
 *  - the previous file is read before it is overwritten, so a site can be put
 *    back byte-for-byte;
 *  - the config is tested after every single site, not once at the end — the
 *    test is server-wide, so testing in a batch tells you something is broken
 *    without telling you which one;
 *  - a site that fails its test is restored and the run *continues*, because
 *    one bad site must not leave the other forty un-updated;
 *  - nothing is reloaded unless at least one site changed and the config
 *    tests clean at the end.
 */
class SiteConfigResyncer
{
    public function __construct(
        private WebServerManager $webServers,
        private ApplicationProvisioner $provisioner,
        private ManagedFile $files,
        private ServerOps $serverOps,
    ) {}

    /**
     * @return array{total: int, updated: int, unchanged: int, failed: array<int, array{id: int, name: string, reference: ?string}>, reloaded: bool}
     */
    public function run(): array
    {
        $driver = $this->webServers->driver();

        $applications = Application::query()
            ->where('status', ApplicationStatus::Active)
            // A disabled site's vhost deliberately points at the disabled
            // page. Re-rendering the real one here would put it back online
            // as a side effect of a panel update.
            ->whereNull('disabled_at')
            ->with(['systemUser', 'botRules', 'wafRules'])
            ->get();

        $updated = 0;
        $unchanged = 0;
        $failed = [];

        foreach ($applications as $application) {
            $path = $driver->configPath($application);
            $previous = $this->read($path);

            $rendered = $driver->renderConfig($application, $this->provisioner->documentRoot($application));

            // Nothing shipped changed for this site. Skipping keeps a routine
            // update from rewriting forty files to identical content.
            if ($previous !== null && $this->normalize($previous) === $this->normalize($rendered)) {
                $unchanged++;

                continue;
            }

            $written = $this->files->put($path, $rendered, $this->context($application, 'resync_write'));

            if ($written->failed()) {
                $failed[] = $this->failure($application, $written->reference);

                continue;
            }

            $test = $driver->test();

            if ($test->failed()) {
                $this->rollback($application, $path, $previous);

                $failed[] = $this->failure($application, $test->reference);

                continue;
            }

            $updated++;
        }

        // One reload for the whole batch: each site was already proved safe
        // individually, and forty reloads would be forty chances to drop a
        // connection for no extra safety.
        $reloaded = false;

        if ($updated > 0 && $driver->test()->ok) {
            $driver->reload();
            $reloaded = true;
        }

        return [
            'total' => $applications->count(),
            'updated' => $updated,
            'unchanged' => $unchanged,
            'failed' => $failed,
            'reloaded' => $reloaded,
        ];
    }

    private function rollback(Application $application, string $path, ?string $previous): void
    {
        if ($previous === null) {
            // There was no file before this run, so removing ours is the
            // restore — via the driver, because on a web server whose site
            // lives partly in a shared file, deleting only the per-site file
            // leaves the shared one pointing at something gone.
            $this->webServers->driver()->remove($application);

            return;
        }

        $this->files->put($path, $previous, $this->context($application, 'resync_rollback'));
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
     * Trailing-whitespace differences are not drift; comparing raw would make
     * every run report every site as updated.
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
