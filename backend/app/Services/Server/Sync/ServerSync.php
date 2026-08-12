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
 * The registry is ordered by dependency rather than by importance: an ssh key
 * belongs to a user, a worker to an application. Running a discoverer before
 * its parent means inventing the parent, so `dependsOn()` is checked rather
 * than trusted to the array order.
 *
 * Every item is written as it is decided, not collected and saved at the end.
 * That is what makes the screen a live feed instead of several silent minutes
 * followed by a number, and it means a run that dies halfway still shows
 * everything it managed to do.
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

        // Loaded once. Asking the database per discovered item would be a
        // query per vhost on a box with two hundred of them.
        $this->ignored = $run->includesIgnored() ? [] : SyncIgnore::keys();

        $totals = [];
        $completed = [];

        try {
            foreach ($this->ordered() as $discoverer) {
                $type = $discoverer->resourceType();

                if ($run->selectedTypes() !== [] && ! in_array($type, $run->selectedTypes(), true)) {
                    continue;
                }

                // A discoverer whose parent did not run would be reading half
                // a picture — every ssh key would look ownerless. Skipped
                // loudly rather than silently producing nothing.
                $missing = array_diff($discoverer->dependsOn(), $completed);

                if ($missing !== []) {
                    $this->record($run, $type, $type, SyncAction::Skipped, [
                        'reason' => 'requires_'.implode('_', $missing),
                    ]);

                    continue;
                }

                $totals[$type] = $this->runOne($run, $discoverer);
                $completed[] = $type;
            }

            $run->forceFill([
                'status' => SyncStatus::Completed,
                'totals' => $totals,
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            // The items written so far stay. A run that failed after adopting
            // nine users is not the same as one that did nothing, and the
            // screen has to be able to tell those apart.
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
            // One resource type failing to read must not end the run: a box
            // with no `ufw` installed should still get its users imported.
            $this->record($run, $type, $type, SyncAction::Failed, ['reason' => 'discovery_failed']);
            $counts['failed']++;

            return $counts;
        }

        foreach ($items as $item) {
            $key = (string) $item['key'];

            // Already answered. Not even recorded as skipped: the point of
            // ignoring something is that it stops appearing, and a list where
            // the same twenty-one lines show up every run is one nobody
            // reads.
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
                // Named, not swallowed. A site the panel could not take on is
                // the one thing the user most needs to see in this list.
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
     * Registered order, but with dependencies honoured — a discoverer added
     * to the registry in the wrong place should still run in the right one.
     *
     * @return array<int, Discoverable>
     */
    private function ordered(): array
    {
        $remaining = $this->discoverers;
        $ordered = [];
        $done = [];

        // Bounded rather than `while (true)`: a dependency cycle, or a
        // dependency on a type that is not registered, would otherwise hang
        // the queue worker instead of producing a result.
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

        // Anything still unresolved goes last and will record its own skip
        // with the dependency it wanted.
        return array_merge($ordered, array_values($remaining));
    }
}
