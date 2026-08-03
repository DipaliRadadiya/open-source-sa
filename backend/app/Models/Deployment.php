<?php

namespace App\Models;

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deployment extends Model
{
    protected $fillable = [
        'application_id', 'user_id', 'trigger', 'status', 'branch',
        'commit_hash', 'commit_message', 'commit_author',
        'steps', 'failed_step', 'reference', 'output',
        'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => DeploymentStatus::class,
            'trigger' => DeploymentTrigger::class,
            'steps' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * Null for a webhook deploy, and that is a fact rather than a gap — nobody
     * pressed anything, so it reads as System.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * How long it took, in seconds.
     *
     * Measured rather than displayed from timestamps by the frontend, so a
     * deploy still running reports null instead of a number that grows every
     * time the page is refreshed.
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
    public function shortCommit(): ?string
    {
        return $this->commit_hash === null ? null : substr($this->commit_hash, 0, 7);
    }
}
