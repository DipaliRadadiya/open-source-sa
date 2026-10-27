<?php

namespace App\Http\Resources;

use App\Services\Server\Cron\CronPhpBinary;
use App\Support\ServerTimezone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CronjobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // Stable, migration-safe identifier (also the cron.d filename key).
            'slug' => $this->slug,
            'username' => $this->username,
            // Present only when the target is a panel-managed System User.
            'system_user' => $this->whenLoaded('systemUser', fn () => $this->systemUser ? [
                'id' => $this->systemUser->id,
                'username' => $this->systemUser->username,
            ] : null),
            // The site this job belongs to, or null for a server-level job —
            // the frontend needs it to know which screen a job came from.
            'application_id' => $this->application_id,
            'command' => $this->command,
            // Which PHP the command runs: "8.2", "default" (plain `php`, the
            // server default) or null when it names none the panel knows.
            'php_version' => app(CronPhpBinary::class)->version((string) $this->command),
            // For a site's job: whether that is the site's PHP. Null for a
            // server-level job, or when either side is unknown.
            'matches_site_php' => $this->application_id
                ? app(CronPhpBinary::class)->matchesSite($this->resource, $this->application)
                : null,
            'expression' => $this->expression,
            'active' => (bool) $this->active,
            // Cron interprets schedules in the server's timezone, so these are
            // computed there — not in the app's timezone.
            'timezone' => ServerTimezone::get(),
            // Key into the Logs endpoints for this job's captured output, or
            // null until it has produced any. Cron discards a job's output by
            // default, so jobs written before output capture existed have
            // nothing to show until they are next saved.
            'log_key' => $this->logKey(),
            // The latest run, from the job's log (list and show only): when,
            // how it ended, how long it took. Null when it has not run.
            'last_run' => $this->when($this->resource->lastRunRead, fn () => $this->resource->lastRun),
            // Its latest run has started and not ended.
            'running' => $this->when($this->resource->lastRunRead, fn () => ($this->resource->lastRun['status'] ?? null) === 'running'),
            'next_run_at' => $this->nextRunAt()?->format('d-m-Y H:i:s'),
            'next_run_at_human' => $this->nextRunAt()?->diffForHumans(),
            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
