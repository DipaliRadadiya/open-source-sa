<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'application_id', 'name', 'command', 'kind', 'directory',
    'processes', 'stop_wait_seconds', 'auto_restart', 'restart_on_deploy', 'enabled',
])]
class Worker extends Model
{
    /** Laravel's own queue worker — restarted gracefully with `queue:restart`. */
    public const KIND_QUEUE = 'queue';

    /** Horizon supervises its own workers — `horizon:terminate`. */
    public const KIND_HORIZON = 'horizon';

    /** Anything else: the unit is restarted directly. */
    public const KIND_CUSTOM = 'custom';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'processes' => 'integer',
            'stop_wait_seconds' => 'integer',
            'auto_restart' => 'boolean',
            'restart_on_deploy' => 'boolean',
            'enabled' => 'boolean',
        ];
    }

    /**
     * Derive the slug on the way in, so no creation path can forget it.
     *
     * A hook rather than each caller's job: the column is NOT NULL because it
     * names a systemd unit, and it is always derived — there is no case where
     * a caller should choose one. Leaving it to the callers meant the
     * controller, the sync adopter and every test creating a worker each had
     * to remember, and the one that forgot failed at the database with a
     * constraint error rather than anywhere useful.
     *
     * Not fillable, so this is the only way it is ever set: a client cannot
     * name the unit the panel writes.
     */
    protected static function booted(): void
    {
        static::creating(function (self $worker): void {
            if (($worker->slug ?? '') !== '') {
                return;
            }

            $applicationSlug = (string) Application::query()
                ->whereKey($worker->application_id)
                ->value('slug');

            $worker->slug = static::uniqueSlug($applicationSlug, (string) $worker->name);
        });
    }

    /**
     * A stable, unique, unit-safe slug — the key for this worker's systemd
     * unit name.
     *
     * Prefixed with the application's slug because `queue` on its own says
     * nothing on a box with twenty sites, and the name someone reads at 2am in
     * `journalctl` should say whose queue it is. Server-wide unique rather than
     * per application, because a unit name is server-wide; suffixes `-2`, `-3`
     * on collision.
     *
     * Uniqueness is enforced on the *slug*, not on the name it came from. The
     * name is already unique per application, but `Str::slug()` is lossy —
     * "My Queue" and "my-queue" reduce to the same string — so checking the
     * name would let two workers claim one unit.
     *
     * Never regenerated on rename. {@see Application::uniqueSlug()} for the
     * same decision about config filenames.
     */
    public static function uniqueSlug(string $applicationSlug, string $name, ?int $ignoreId = null): string
    {
        $base = trim(Str::slug($applicationSlug).'-'.Str::slug($name), '-') ?: 'worker';
        $slug = $base;
        $suffix = 2;

        while (static::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * Horizon and a plain queue worker on the same application both consume
     * the same queue, so every job is picked up twice. Neither tool can detect
     * the other, which is why the panel has to.
     */
    public function conflictsWith(Worker $other): bool
    {
        $queueing = [self::KIND_QUEUE, self::KIND_HORIZON];

        return $this->kind !== $other->kind
            && in_array($this->kind, $queueing, true)
            && in_array($other->kind, $queueing, true);
    }
}
