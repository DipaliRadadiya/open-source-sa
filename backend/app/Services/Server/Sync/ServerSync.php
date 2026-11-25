<?php

namespace App\Services\Server\Sync;

use App\Contracts\Discoverable;
use App\Enums\SyncAction;
use App\Enums\SyncStatus;
use App\Models\SyncIgnore;
use App\Models\SyncItem;
use App\Models\SyncRun;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads the server and records what is on it, one line at a time.
 *
 * Discoverers run in dependency order (an ssh key belongs to a user, a worker to an
 * application), so `dependsOn()` is checked rather than trusted to the array order.
 *
 * Every item is written as it is decided: the screen is a live feed, and a run that dies
 * halfway still shows what it did.
 */
class ServerSync
{
    /**
     * `type:key` strings the user has already said no to, for this run.
     *
     * @var array<int, string>
     */
    private array $ignored = [];

    /**
     * @param  array<int, Discoverable>  $discoverers
     */
    public function __construct(private array $discoverers) {}

    /**
     * @return array<int, string>
     */
    public function resourceTypes(): array
    {
        return array_map(fn (Discoverable $d): string => $d->resourceType(), $this->discoverers);
    }

    public function run(SyncRun $run): SyncRun
    {
        $run->forceFill(['status' => SyncStatus::Running, 'started_at' => now()])->save();

        // Loaded once, not a query per discovered item.
        $this->ignored = $run->includesIgnored() ? [] : SyncIgnore::keys();

        $totals = [];
        $completed = [];

        try {
            foreach ($this->ordered() as $discoverer) {
                $type = $discoverer->resourceType();

                if ($run->selectedTypes() !== [] && ! in_array($type, $run->selectedTypes(), true)) {
                    continue;
                }

                // Without its parent a discoverer sees half a picture (every ssh key would look
                // ownerless), so it is recorded as skipped.
                $missing = array_diff($discoverer->dependsOn(), $completed);

                if ($missing !== []) {
                    $this->record($run, $type, $type, SyncAction::Skipped, [
                        'reason' => 'requires_'.implode('_', $missing),
                    ]);

                    continue;
                }

                $totals[$type] = $this->runOne($run, $discoverer);
                $completed[] = $type;

                // A preview adopts no sites, so their workers, certificates and PHP settings have
                // nothing to belong to. Recorded once per type so the preview does not under-report.
                if (! $run->mode->writes()
                    && in_array('application', $discoverer->dependsOn(), true)
                    && $run->items()->where('resource_type', 'application')->where('action', SyncAction::Found)->exists()) {
                    $this->record($run, $type, $type, SyncAction::Skipped, ['reason' => 'after_sites_adopted']);
                }
            }

            $run->forceFill([
                'status' => SyncStatus::Completed,
                'totals' => $totals,
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            // The items written so far stay, so the screen can tell a partial run from an empty one.
            Log::channel('server-ops')->error('server sync failed', [
                'feature' => 'sync',
                'op' => 'run',
                'sync_run' => $run->id,
                'detail' => $e->getMessage(),
            ]);

            $run->forceFill([
                'status' => SyncStatus::Failed,
                'totals' => $totals,
                'finished_at' => now(),
            ])->save();
        }

        return $run->fresh();
    }

    /**
     * @return array{found: int, adopted: int, skipped: int, failed: int}
     */
    private function runOne(SyncRun $run, Discoverable $discoverer): array
    {
        $type = $discoverer->resourceType();
        $counts = ['found' => 0, 'adopted' => 0, 'skipped' => 0, 'failed' => 0];

        try {
            $items = $discoverer->discover($run);
        } catch (Throwable $e) {
            // One type failing to read must not end the run (no `ufw` should not block users).
            $this->record($run, $type, $type, SyncAction::Failed, ['reason' => 'discovery_failed']);
            $counts['failed']++;

            return $counts;
        }

        foreach ($items as $item) {
            $key = (string) $item['key'];

            // Ignored items are not recorded at all: ignoring means it stops appearing.
            if (in_array($type.':'.$key, $this->ignored, true)) {
                continue;
            }

            if (isset($item['skip'])) {
                $this->record($run, $type, $key, SyncAction::Skipped, $item + ['reason' => $item['skip']]);
                $counts['skipped']++;

                continue;
            }

            if (! $run->mode->writes()) {
                $this->record($run, $type, $key, SyncAction::Found, $item);
                $counts['found']++;

                continue;
            }

            try {
                $model = $discoverer->adopt($item);

                $this->record($run, $type, $key, SyncAction::Adopted, $item, $model);
                $counts['adopted']++;
            } catch (Throwable $e) {
                // Recorded, not swallowed: a site the panel could not adopt must show in the list.
                $this->record($run, $type, $key, SyncAction::Failed, $item + [
                    'reason' => 'adopt_failed',
                ]);
                $counts['failed']++;
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function record(
        SyncRun $run,
        string $type,
        string $key,
        SyncAction $action,
        array $item = [],
        ?object $model = null,
    ): void {
        SyncItem::create([
            'sync_run_id' => $run->id,
            'resource_type' => $type,
            'resource_key' => $key,
            'action' => $action,
            'model_type' => $model ? $model::class : null,
            'model_id' => $model?->getKey(),
            'confidence' => $item['confidence'] ?? null,
            'evidence' => $item['evidence'] ?? null,
            'reason' => $item['reason'] ?? null,
        ]);
    }

    /**
     * Registered order, with dependencies honoured.
     *
     * @return array<int, Discoverable>
     */
    private function ordered(): array
    {
        $remaining = $this->discoverers;
        $ordered = [];
        $done = [];

        // Bounded: a dependency cycle or an unregistered dependency must not hang the queue worker.
        for ($pass = 0; $pass < count($this->discoverers) + 1 && $remaining !== []; $pass++) {
            foreach ($remaining as $index => $discoverer) {
                if (array_diff($discoverer->dependsOn(), $done) !== []) {
                    continue;
                }

                $ordered[] = $discoverer;
                $done[] = $discoverer->resourceType();
                unset($remaining[$index]);
            }
        }

        // Unresolved ones go last and record their own skip.
        return array_merge($ordered, array_values($remaining));
    }
}
