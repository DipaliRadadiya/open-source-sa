<?php

namespace App\Services\Server\Php;

use App\Models\Application;
use App\Models\ApplicationPhpSettings;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\WebServers\WebServerManager;

/**
 * Give a site its own FPM pool, and put its vhost on the matching socket.
 *
 * Provisioning does this for new PHP sites; this covers adopted sites, sites made before pool
 * isolation, and sites whose `create_php_pool` step failed. There is no shared-pool mode: it
 * would let one compromised site read every other site's `.env`.
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
     * @return array{ok: bool, reason?: string, reference?: string|null, adoption?: array{adopted: bool, kept: array<int, string>, dropped: array<int, string>}}
     */
    public function isolate(Application $application): array
    {
        // Static and Node sites have no pool: isolating one would write a pool file under the
        // server's default PHP version and reload PHP-FPM for nothing.
        if ($application->serving_profile !== 'php') {
            return ['ok' => false, 'reason' => 'not_php_site'];
        }

        $settings = $application->phpSettings ?? new ApplicationPhpSettings([
            'application_id' => $application->id,
        ]);

        // Before the first write: a pool file the panel did not create may carry a deliberate
        // open_basedir that rendering ours would silently drop.
        $adoption = $this->pools->adoptOpenBasedir($application, $settings);

        $result = $this->pools->apply($application, $settings);

        if (! $result['ok']) {
            // Nothing was reloaded, so the site is still served as before.
            return $result;
        }

        $application->forceFill(['isolated_at' => now()])->save();

        // Vhost last: it needs the pool's socket to be live, or the site 502s.
        $this->republish($application);

        return $result + ['adoption' => $adoption];
    }

    /**
     * Rewrite a site's vhost from the current state and reload, tested first.
     *
     * The document root comes from the provisioner, the single source of truth for the layout.
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
     * Used by `php:isolate-all` after an upgrade to convert existing sites in one pass.
     *
     * @return array{total: int, isolated: int, skipped: int, adopted: array<int, array{name: string, kept: array<int, string>, dropped: array<int, string>}>, failed: array<int, array{id: int, name: string, reason: string}>}
     */
    public function isolateAll(): array
    {
        $applications = Application::query()
            ->with(['systemUser', 'phpSettings'])
            ->where('serving_profile', 'php')
            ->whereNull('isolated_at')
            ->get();

        $isolated = 0;
        $adopted = [];
        $failed = [];

        foreach ($applications as $application) {
            $result = $this->isolate($application);

            if ($result['ok']) {
                $isolated++;

                // Reported: taking over an existing open_basedir changes what a site can reach.
                if (($result['adoption']['adopted'] ?? false) === true) {
                    $adopted[] = [
                        'name' => $application->name,
                        'kept' => $result['adoption']['kept'],
                        'dropped' => $result['adoption']['dropped'],
                    ];
                }

                continue;
            }

            // Carry on: one failing site must not stop the rest from converting.
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
            'adopted' => $adopted,
            'failed' => $failed,
        ];
    }
}
