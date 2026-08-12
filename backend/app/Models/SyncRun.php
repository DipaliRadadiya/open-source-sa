<?php

namespace App\Models;

use App\Enums\SyncMode;
use App\Enums\SyncStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function items(): HasMany
    {
        return $this->hasMany(SyncItem::class);
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
