<?php

namespace App\Models;

use App\Support\ServerTimezone;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'username', 'system_user_id', 'command', 'expression', 'active'])]
class Cronjob extends Model
{
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

        $date = (new CronExpression((string) $this->expression))
            ->getNextRunDate('now', 0, false, $timezone);

        return Carbon::instance($date)->setTimezone($timezone);
    }

    public function systemUser(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class);
    }
}
