<?php

namespace App\Models;

use App\Enums\PanelUpdateStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PanelUpdate extends Model
{
    protected $fillable = [
        'user_id', 'status', 'reason', 'reference',
        'from_version', 'from_commit', 'to_version', 'to_commit',
        'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PanelUpdateStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Null for a system-initiated update (none today, but the slot exists for
     * the same reason it exists on `Deployment::user()`). Renders as System,
     * not as a missing field.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * How long it took, in seconds.
     *
     * Measured rather than displayed from timestamps by the frontend, so an
     * update still running reports null instead of a number that grows every
     * time the page is refreshed — the same reason `Deployment::duration()`
     * does it this way.
     */
    public function duration(): ?int
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at);
    }

    /**
     * The short hash people actually recognise.
     */
    public function shortCommit(?string $hash): ?string
    {
        return $hash === null ? null : substr($hash, 0, 7);
    }

    /**
     * The translated sentence for a failure, in the *viewer's* locale rather
     * than the locale of whoever queued the update — same rule the install
     * rows follow. A missing translation falls back to the generic key
     * rather than rendering the key itself.
     */
    public function message(): ?string
    {
        if ($this->status !== PanelUpdateStatus::Failed) {
            return null;
        }

        $key = 'panel_update.reason.'.($this->reason ?: 'unknown');
        $replacements = ['reference' => (string) $this->reference];

        $rendered = __($key, $replacements);

        return $rendered === $key
            ? __('panel_update.reason.unknown', $replacements)
            : $rendered;
    }
}
