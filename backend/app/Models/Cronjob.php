<?php

namespace App\Models;

use App\Support\ServerTimezone;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'source_path', 'username', 'system_user_id', 'application_id', 'command', 'expression', 'active'])]
class Cronjob extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * A stable, unique slug from the name — the key for the cron.d filename.
     * Suffixes `-2`, `-3`, … on collision. Migration-safe (stored, not derived
     * from the auto-increment id).
     */
    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'cronjob';
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

    /**
     * When cron will next run this job.
     *
     * Computed in the SERVER's timezone, not the app's — cron interprets
     * schedules against the OS clock, so computing in UTC would be wrong by
     * the offset. Null for an inactive job, because an inactive job has no
     * next run.
     */
    public function nextRunAt(): ?Carbon
    {
        if (! $this->active || ! CronExpression::isValidExpression((string) $this->expression)) {
            return null;
        }

        $timezone = ServerTimezone::get();

        // Carbon::now() rather than the string 'now': the string is resolved
        // by the cron library against the real clock, which ignores a frozen
        // test time — so a test that appeared to pin the date was really
        // asserting against whatever day it ran on, and passed until it
        // didn't.
        $date = (new CronExpression((string) $this->expression))
            ->getNextRunDate(Carbon::now(), 0, false, $timezone);

        return Carbon::instance($date)->setTimezone($timezone);
    }

    public function systemUser(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class);
    }

    /** The application this job belongs to, if any. Null = server-level job. */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * This job's key in the Logs feature, or null when no output has been
     * captured yet.
     *
     * Gated on the file existing rather than on a stored flag: the log is
     * created when the panel writes the job's cron.d entry, so its presence is
     * the honest answer to "is there anything to show?" — and a job whose
     * entry predates output capture correctly reports nothing.
     */
    public function logKey(): ?string
    {
        if ($this->slug === null) {
            return null;
        }

        $dir = rtrim((string) config('server.cronjob_log_dir', '/var/log/cronjobs'), '/');

        return is_file("{$dir}/{$this->slug}.log") ? "cronjob_{$this->slug}" : null;
    }
}
