<?php

namespace App\Models;

use App\Enums\SecurityUpdateStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One security update the panel was asked to install.
 *
 * Unlike `runtime_installs`, a finished row is **kept**. That table deletes on
 * success because the disk answers "is PHP 8.4 installed" better than a row
 * could, and a stored answer would be free to disagree with it. Nothing on this
 * box records that a security update ran and what it did — apt's logs are
 * rotated and unparsed — so the row *is* the record, and deleting it would
 * throw away the only durable account of the thing being audited.
 */
class SecurityUpdateRun extends Model
{
    protected $fillable = [
        'user_id', 'status', 'reason', 'reference', 'exit_code',
        'packages_upgraded', 'output', 'reboot_required_after',
        'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SecurityUpdateStatus::class,
            'reboot_required_after' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The run, as the settings screen reads it.
     *
     * `reason` is a code and not a sentence: the frontend owns the wording here,
     * the same way it already does for `unattended_last_result` in the same
     * card. `output` is withheld from a viewer who cannot manage settings —
     * apt's output can carry conffile diffs, debconf answers and mirror URLs,
     * so it follows the heavier permission, as the failed-run excerpt beside it
     * does.
     */
    public function toProgress(bool $withOutput): array
    {
        return [
            'id' => $this->getKey(),
            'status' => $this->status->value,
            'reason' => $this->reason,
            'reference' => $this->reference,
            'exit_code' => $this->exit_code,
            'packages_upgraded' => $this->packages_upgraded,
            'reboot_required_after' => $this->reboot_required_after,
            'output' => $withOutput ? $this->output : null,
            'started_at' => $this->started_at?->format('d-m-Y H:i:s'),
            'started_at_human' => $this->started_at?->diffForHumans(),
            'finished_at' => $this->finished_at?->format('d-m-Y H:i:s'),
            'finished_at_human' => $this->finished_at?->diffForHumans(),
        ];
    }
}
