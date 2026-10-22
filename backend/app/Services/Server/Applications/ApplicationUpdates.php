<?php

namespace App\Services\Server\Applications;

use App\Contracts\ApplicationUpdater;
use App\Enums\ApplicationStatus;
use App\Enums\ApplicationUpdateStatus;
use App\Enums\BackupStatus;
use App\Models\Application;
use App\Models\ApplicationUpdate;
use App\Models\Backup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * What the one-click update screen needs to know about a site, and whether an
 * update may start.
 *
 * Release lookups are cached: they reach GitHub, Packagist and the projects'
 * own sites, GitHub allows 60 unauthenticated requests an hour, and the
 * screen asks every time it opens. A failed lookup is not cached, so the next
 * visit tries again instead of reporting "unknown" for the cache lifetime.
 */
class ApplicationUpdates
{
    private const CACHE_SECONDS = 1800;

    public function __construct(private InstallerManager $installers) {}

    public function updaterFor(Application $application): ?ApplicationUpdater
    {
        $installer = $this->installers->installerFor($application);

        return $installer instanceof ApplicationUpdater ? $installer : null;
    }

    /**
     * @return array{supported: bool, installed_version: ?string, latest_version: ?string, newest_version: ?string, update_available: bool, newer_major: ?string, blocked_reason: ?string, last_backup_at: ?string}
     */
    public function status(Application $application): array
    {
        $updater = $this->updaterFor($application);
        $status = [
            'supported' => $updater !== null,
            'installed_version' => null,
            'latest_version' => null,
            'newest_version' => null,
            'update_available' => false,
            'newer_major' => null,
            'blocked_reason' => null,
            // Shown beside the button: the backup is the user's to take first,
            // from the backups screen (Dipali, 2026-10-13).
            // `max()` returns the column's raw string (`Y-m-d H:i:s`); every other
            // date in the API, history[] here included, is `d-m-Y H:i:s` (RC-10).
            'last_backup_at' => ($last = Backup::query()
                ->where('application_id', $application->id)
                ->where('status', BackupStatus::Verified)
                ->max('finished_at')) === null ? null : Carbon::parse($last)->format('d-m-Y H:i:s'),
        ];

        if ($updater === null) {
            return ['blocked_reason' => 'unsupported'] + $status;
        }

        if ($application->status !== ApplicationStatus::Active || $application->disabled_at !== null) {
            return ['blocked_reason' => 'not_active'] + $status;
        }

        $installed = $updater->installedVersion($application);

        if ($installed === null) {
            return ['blocked_reason' => 'version_unknown'] + $status;
        }

        $available = $this->available($application, $updater, $installed);
        $latest = $available['latest'];
        $newest = $available['newest'];
        $updateAvailable = $latest !== null && version_compare($latest, $installed, '>');

        return array_merge($status, [
            'installed_version' => $installed,
            'latest_version' => $latest,
            'newest_version' => $newest,
            'update_available' => $updateAvailable,
            // A release past the installed line (major; Moodle's branch).
            // Reported, never applied: crossing it is the user's migration.
            'newer_major' => $newest !== null && $latest !== null && version_compare($newest, $latest, '>') ? $newest : null,
            'blocked_reason' => match (true) {
                $this->running($application) !== null => 'running',
                $latest === null => 'lookup_failed',
                ! $updateAvailable => 'up_to_date',
                default => null,
            },
        ]);
    }

    public function running(Application $application): ?ApplicationUpdate
    {
        return ApplicationUpdate::query()
            ->where('application_id', $application->id)
            ->whereIn('status', ApplicationUpdateStatus::active())
            ->latest('id')
            ->first();
    }

    /** @return array{latest: ?string, newest: ?string} */
    private function available(Application $application, ApplicationUpdater $updater, string $installed): array
    {
        $key = "app-update:{$application->site_type}:{$installed}";
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $available = $updater->availableVersions($application, $installed);

        if ($available['latest'] !== null) {
            Cache::put($key, $available, self::CACHE_SECONDS);
        }

        return $available;
    }
}
