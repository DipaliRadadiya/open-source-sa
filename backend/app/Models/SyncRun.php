<?php

namespace App\Models;

use App\Enums\SyncAction;
use App\Enums\SyncMode;
use App\Enums\SyncStatus;
use App\Jobs\RunServerSync;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

#[Fillable(['user_id', 'mode', 'status', 'options', 'totals', 'reference', 'started_at', 'finished_at'])]
class SyncRun extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => SyncMode::class,
            'status' => SyncStatus::class,
            'options' => 'array',
            'totals' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * How long a run may stay unfinished before it is treated as dead: the job's timeout plus a
     * minute, so a slow run is never declared stale while working.
     */
    public const STALE_AFTER = RunServerSync::TIMEOUT + 60;

    public function items(): HasMany
    {
        return $this->hasMany(SyncItem::class);
    }

    /**
     * Accounts this preview found and an apply would adopt, username => home path. Always empty
     * for an apply, which has already adopted them.
     *
     * A preview writes nothing, so site, key and cron discoverers cannot find their owner in the
     * panel; this lets them see the accounts the run would adopt. Read from this run's own
     * items, so an ignored or skipped account is not in it.
     *
     * @return Collection<string, string|null>
     */
    public function previewedSystemUsers(): Collection
    {
        // Null mode (column default) means preview.
        if ($this->mode?->writes() === true) {
            return new Collection;
        }

        return $this->items()
            ->where('resource_type', 'system_user')
            ->where('action', SyncAction::Found)
            ->get(['resource_key', 'evidence'])
            ->mapWithKeys(fn (SyncItem $item): array => [$item->resource_key => $item->evidence['home_path'] ?? null]);
    }

    /**
     * Unfinished, and old enough that nothing is going to finish it.
     *
     * `RunServerSync::failed()` never fires when no queue worker runs (`pending` forever) or the
     * worker is killed outright (`running` forever). The start-a-run guard reads this state, so
     * a stuck run would refuse every later sync with no way to clear it.
     */
    public function isStale(): bool
    {
        if ($this->status->finished()) {
            return false;
        }

        $since = $this->started_at ?? $this->created_at;

        return $since === null || $since->lt(now()->subSeconds(self::STALE_AFTER));
    }

    /**
     * Mark this run failed if it is stale. Returns whether it did.
     */
    public function failIfStale(): bool
    {
        if (! $this->isStale()) {
            return false;
        }

        Log::channel('server-ops')->warning('server sync run abandoned', [
            'feature' => 'sync',
            'op' => 'reap_stale',
            'sync_run' => $this->id,
            'status' => $this->status->value,
            // `pending` means the job was never picked up: the queue worker is not running.
            'detail' => 'unfinished for longer than the job could run',
        ]);

        $this->forceFill([
            'status' => SyncStatus::Failed,
            'finished_at' => now(),
        ])->save();

        return true;
    }

    /**
     * Fail every stale run, and report whether any live run is left.
     */
    public static function hasLiveRun(): bool
    {
        return static::query()
            ->whereIn('status', [SyncStatus::Pending, SyncStatus::Running])
            ->get()
            ->reject(fn (self $run) => $run->failIfStale())
            ->isNotEmpty();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The resource types this run was asked to cover, or all of them.
     *
     * Not named `only()`: that is Eloquent's own method and overriding it is a fatal error.
     *
     * @return array<int, string>
     */
    public function selectedTypes(): array
    {
        return (array) ($this->options['only'] ?? []);
    }

    /**
     * Whether this run should show things the user has already dismissed. Off by default, but a
     * run must be able to show them or a mistaken ignore cannot be undone.
     */
    public function includesIgnored(): bool
    {
        return (bool) ($this->options['include_ignored'] ?? false);
    }

    /**
     * Firewall rules are opt-in: adopting one lets a later sync decide it is the panel's to
     * remove, which cannot be undone from the panel.
     */
    public function includesFirewall(): bool
    {
        return (bool) ($this->options['include_firewall'] ?? false);
    }
}
