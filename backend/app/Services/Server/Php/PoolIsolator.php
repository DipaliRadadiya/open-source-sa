<?php

namespace App\Services\Server\Php;

use App\Models\Application;
use App\Models\ApplicationPhpSettings;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\WebServers\WebServerManager;

/**
 * Give a site its own FPM pool, and put its vhost on the matching socket.
 *
 * Provisioning already does this for every new PHP site, so the only sites
 * that ever need it are the ones the panel did not create: adopted from
 * another panel, made before pool isolation shipped, or left behind by a
 * `create_php_pool` step that failed. There is no "shared pool" mode to
 * choose — running as the web server's own account means one compromised
 * site can read every other site's `.env`, which is the thing isolation
 * exists to prevent.
 */
class PoolIsolator
{
    public function __construct(
        private PoolManager $pools,
        private WebServerManager $webServers,
        private ApplicationProvisioner $provisioner,
    ) {}

    public function supported(): bool
    {
        return $this->pools->supported();
    }

    /**
     * @return array{ok: bool, reason?: string, reference?: string|null}
     */
    public function isolate(Application $application): array
    {
        $settings = $application->phpSettings ?? new ApplicationPhpSettings([
            'application_id' => $application->id,
        ]);

        $result = $this->pools->apply($application, $settings);

        if (! $result['ok']) {
            // Nothing was reloaded, so the site is still being served exactly
            // as it was a moment ago.
            return $result;
        }

        $application->forceFill(['isolated_at' => now()])->save();

        // Vhost last: it can only point at the socket once the pool that owns
        // that socket is live, or the site 502s in the gap.
        $this->republish($application);

        return $result;
    }

    /**
     * Rewrite a site's vhost from the current state and reload, tested first.
     *
     * The document root comes from the provisioner rather than being rebuilt
     * here. The copy that used to live in the PHP controller was wrong in two
     * ways — it used `domain` where the real path uses `slug`, and it omitted
     * `public_html` entirely — so every republish pointed the site at a
     * directory that does not exist. One source of truth, so a change to the
     * layout cannot leave a second copy behind.
     */
    public function republish(Application $application): void
    {
        $driver = $this->webServers->driver();

        $driver->apply($application, $this->provisioner->documentRoot($application->loadMissing('systemUser')));

        if ($driver->test()->ok) {
            $driver->reload();
        }
    }

    /**
     * Isolate every PHP site that does not have a pool yet.
     *
     * Used by `php:isolate-all` after an upgrade, so a server carrying sites
     * from before this feature converts in one pass instead of the admin
     * opening each site's PHP screen.
     *
     * @return array{total: int, isolated: int, skipped: int, failed: array<int, array{id: int, name: string, reason: string}>}
     */
    public function isolateAll(): array
    {
        $applications = Application::query()
            ->with(['systemUser', 'phpSettings'])
            ->where('serving_profile', 'php')
            ->whereNull('isolated_at')
            ->get();

        $isolated = 0;
        $failed = [];

        foreach ($applications as $application) {
            $result = $this->isolate($application);

            if ($result['ok']) {
                $isolated++;

                continue;
            }

            // Carry on rather than aborting: one site whose pool will not
            // write should not stop the rest of the server converting, and
            // that site is still being served meanwhile.
            $failed[] = [
                'id' => $application->id,
                'name' => $application->name,
                'reason' => (string) ($result['reason'] ?? 'unknown'),
            ];
        }

        return [
            'total' => $applications->count(),
            'isolated' => $isolated,
            'skipped' => 0,
            'failed' => $failed,
        ];
    }
}
