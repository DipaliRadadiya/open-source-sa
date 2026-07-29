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
            'command' => $this->command,
            'expression' => $this->expression,
            'active' => (bool) $this->active,
            // Cron interprets schedules in the server's timezone, so these are
            // computed there — not in the app's timezone.
            'timezone' => ServerTimezone::get(),
            'next_run_at' => $this->nextRunAt()?->format('d-m-Y H:i:s'),
            'next_run_at_human' => $this->nextRunAt()?->diffForHumans(),
            // The previous SCHEDULED time — not proof the job ran. Cron keeps
            // no record of actual executions.
            'previous_run_at' => $this->previousRunAt()?->format('d-m-Y H:i:s'),
            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
