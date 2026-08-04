<?php

namespace App\Actions\Admin\PanelUpdate;

use App\Enums\PanelUpdateStatus;
use App\Exceptions\Admin\PanelUpdate\PanelUpdateAlreadyInProgressException;
use App\Jobs\PerformPanelUpdate;
use App\Models\PanelUpdate;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Panel\InstalledPanelInfo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Record a requested panel update and dispatch the worker.
 *
 * Two guards keep this from creating more than one row at a time:
 *
 *  - **Cache lock** — protects the small window between "started checking"
 *    and "row committed". A second concurrent POST holding this lock for ten
 *    seconds is the only way two rows could be created back to back; without
 *    it the database check below can pass for both because neither row is
 *    visible yet.
 *
 *  - **Database check** — even with the lock, an old `pending`/`running`
 *    row from a previous request that never ran (worker down, job crashed
 *    before its `failed()` hook) would be missed. The DB check is the
 *    persistent guard; the cache lock is the in-flight guard.
 *
 * Both together is belt and braces by design — either alone leaves a gap.
 */
class QueuePanelUpdate
{
    private const LOCK_KEY = 'panel-update:queue';

    private const LOCK_SECONDS = 10;

    public function __construct(
        private ActivityLogger $activityLogger,
        private InstalledPanelInfo $installed,
    ) {}

    public function execute(): PanelUpdate
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        // Non-blocking acquire. A second request while the first is still
        // creating the row turns into a 409 immediately, which is what the
        // admin who clicked twice will see as "your update is already
        // queued", not a duplicate row.
        if (! $lock->get()) {
            $this->refuseInFlight();
        }

        try {
            return DB::transaction(function (): PanelUpdate {
                if (PanelUpdate::query()
                    ->whereIn('status', [
                        PanelUpdateStatus::Pending->value,
                        PanelUpdateStatus::Running->value,
                    ])
                    ->exists()
                ) {
                    $this->refuseInFlight();
                }

                $installed = $this->installed->installed();

                $actor = Auth::user();
                $actorId = $actor instanceof User ? $actor->getKey() : null;

                $update = PanelUpdate::create([
                    'user_id' => $actorId,
                    'status' => PanelUpdateStatus::Pending,
                    // What we're starting from, snapshotted now — the future
                    // rollback helper reads these, not `installed()` again,
                    // so it sees the moment the user asked, not the moment
                    // the switch runs.
                    'from_version' => $installed['version'],
                    'from_commit' => $installed['commit_hash'],
                ]);

                $this->activityLogger->log(
                    'panel_update.queued',
                    $update,
                    [
                        'from_version' => $installed['version'],
                        'from_commit' => $installed['commit_hash'],
                    ],
                    actor: $actor,
                );

                // The job runs even though the worker is currently a no-op —
                // it records the same row's transition through `running` and
                // closes with `failed` / reason `unsupported`, so the screen
                // has a real lifecycle to render instead of a row stuck at
                // `pending` forever.
                PerformPanelUpdate::dispatch($update->id, $actorId);

                return $update;
            });
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Translate "another update is already queued" into a typed exception the
     * controller renders with a stable reference — the raw "row exists" check
     * must not reach the API.
     */
    private function refuseInFlight(): never
    {
        $reference = (string) Str::uuid();

        Log::warning('Refused a panel update because another is already in flight.', [
            'reference' => $reference,
        ]);

        throw new PanelUpdateAlreadyInProgressException($reference);
    }
}
