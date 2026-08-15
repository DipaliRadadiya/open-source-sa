<?php

namespace App\Http\Resources;

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
            'next_run_at' => $this->nextRunAt()?->format('d-m-Y H:i:s'),
            'next_run_at_human' => $this->nextRunAt()?->diffForHumans(),
            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
