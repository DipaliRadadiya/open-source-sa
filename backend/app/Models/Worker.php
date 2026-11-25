<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'application_id', 'name', 'command', 'kind', 'directory',
    'processes', 'stop_wait_seconds', 'auto_restart', 'restart_on_deploy', 'enabled',
    // supervisord's own directives. `user` is fillable because an adopted block may name an
    // account other than the site's, which must be kept.
    'user', 'log_file', 'log_level', 'extra_config', 'auto_start',
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
     * The longest a worker may be given to finish its job before it is killed, and what a queue
     * worker starts with.
     *
     * Supervisor's stopwaitsecs must outlast the longest job (the queue preset runs up to an hour,
     * `--max-time=3600`). An idle worker stops at once, so a high ceiling costs nothing.
     */
    public const MAX_STOP_WAIT = 3600;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'program' => 'string',
            'processes' => 'integer',
            'stop_wait_seconds' => 'integer',
            'auto_restart' => 'boolean',
            'auto_start' => 'boolean',
            'restart_on_deploy' => 'boolean',
            'enabled' => 'boolean',
        ];
    }

    /**
     * Derive the slug on the way in, so no creation path can forget it.
     *
     * The column is NOT NULL and always derived (it names a systemd unit). Not fillable, so this
     * is the only way it is set: a client cannot name the unit the panel writes.
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
     * A stable, unique, unit-safe slug: the key for this worker's systemd unit name.
     *
     * Prefixed with the application's slug so `journalctl` shows whose queue it is. Unique
     * server-wide (unit names are), with `-2`, `-3` suffixes on collision. Uniqueness is checked on
     * the slug, not the name, because `Str::slug()` is lossy ("My Queue" and "my-queue" collide).
     *
     * Never regenerated on rename. {@see Application::uniqueSlug()} for the same decision about
     * config filenames.
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

    public function hasProgram(): bool
    {
        return $this->program !== null && $this->program !== '';
    }

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * Horizon and a plain queue worker on the same application consume the same queue, so every
     * job runs twice. Neither can detect the other.
     */
    public function conflictsWith(Worker $other): bool
    {
        $queueing = [self::KIND_QUEUE, self::KIND_HORIZON];

        return $this->kind !== $other->kind
            && in_array($this->kind, $queueing, true)
            && in_array($other->kind, $queueing, true);
    }
}
