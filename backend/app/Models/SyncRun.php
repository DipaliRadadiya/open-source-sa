<?php

namespace App\Models;

use App\Enums\SyncMode;
use App\Enums\SyncStatus;
use App\Jobs\RunServerSync;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
     * How long after it should have finished a run is treated as dead. The
     * job's own timeout plus a minute, so a run that is merely slow is never
     * declared stale while it is still working.
     */
    public const STALE_AFTER = RunServerSync::TIMEOUT + 60;

    public function items(): HasMany
    {
        return $this->hasMany(SyncItem::class);
    }

    /**
     * Unfinished, and old enough that nothing is going to finish it.
     *
     * `RunServerSync::failed()` handles the ordinary death — a timeout, an
     * exception — but it only runs if a worker picked the job up and lived long
     * enough to report. It never fires when the queue worker is not running at
     * all (the run stays `pending` forever) or when the worker is killed
     * outright by the OOM killer or a reboot (`running` forever).
     *
     * That matters because the guard on starting a run reads this state: a run
     * stuck here refused every later sync, permanently, with no way to clear it
     * from any screen. A guard that cannot be cleared is worse than no guard.
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
            // `pending` here means the job was never picked up at all, which is
            // a queue worker that is not running — and that breaks far more
            // than this feature.
            'detail' => 'unfinished for longer than the job could run',
        ]);

        $this->forceFill([
            'status' => SyncStatus::Failed,
            'finished_at' => now(),
        ])->save();

        return true;
    }

    /**
     * Fail every run that is stale, and report whether any genuinely live run
     * is left.
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
     * Not `only()` — that is Eloquent's own method for picking attributes, and
     * overriding it with a different signature is a fatal error at load time.
     *
     * @return array<int, string>
     */
    public function selectedTypes(): array
    {
        return (array) ($this->options['only'] ?? []);
    }

    /**
     * Firewall rules are opt-in. Adopting a rule means a later sync could
     * decide it is the panel's to remove, and that is the one mistake here
     * that cannot be undone from the panel.
     */
    /**
     * Whether this run should show things the user has already dismissed.
     *
     * Off by default — that is what makes ignoring worth doing — but a run
     * has to be able to show them, or an ignore made by mistake is
     * unreachable from the screen that made it.
     */
    public function includesIgnored(): bool
    {
        return (bool) ($this->options['include_ignored'] ?? false);
    }

    public function includesFirewall(): bool
    {
        return (bool) ($this->options['include_firewall'] ?? false);
    }
}
