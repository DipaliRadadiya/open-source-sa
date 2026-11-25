<?php

namespace App\Http\Resources;

use App\Models\Backup;
use App\Services\Server\Backups\StaleBackupReaper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Backup */
class BackupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // The archive's name in the bucket. `id` only means something inside this panel's
            // database. Read-only: assigned in `Backup::booted()`, not fillable.
            'uid' => $this->uid,
            'application_id' => $this->application_id,
            'application_name' => $this->resource->application?->name,
            'application_domain' => $this->resource->application?->domain,
            // After a database is deleted `database_id` is null and `archived` is true, but the
            // name and engine stay.
            'database_id' => $this->database_id,
            'database_name' => $this->resource->isDatabaseBackup() ? $this->resource->subjectName() : null,
            'database_engine' => $this->subject_engine,
            'archived' => $this->resource->isArchived(),
            // A site's backup kept after the site was deleted: its domain.
            'site_name' => ! $this->resource->isDatabaseBackup() && $this->application_id === null ? $this->subject_name : null,
            // Where this archive actually went, from the backup's own recorded destination, not
            // through the target: the target's destination is editable, so repointing it would
            // relabel every past archive. `whenLoaded` leaves the key absent (not null) when the
            // relation was not eager-loaded, so "no destination" differs from "not asked for".
            'storage_destination_name' => $this->whenLoaded(
                'storageDestination',
                fn (): ?string => $this->resource->storageDestination?->name,
            ),
            'type' => $this->type->value,
            // Whether the archive holds a database dump; not the same as the type (a site with no
            // database has `full` backups without one). Offer database restores only when true.
            'has_database' => $this->resource->holdsDatabase(),
            // Taken automatically just before a restore overwrites the site; exempt from retention.
            'is_safety' => (bool) $this->is_safety,
            'status' => $this->status->value,
            // Whether `POST /backups/{id}/clear` would accept this row: in flight and past the
            // point where anything can still be working on it. Computed here so the frontend does
            // not keep its own copy of the reaper's rule.
            'is_stale' => $stale = app(StaleBackupReaper::class)->isStale($this->resource),
            'clearable' => $stale,
            'status_title' => __('backup.status.'.$this->status->value),
            'type_title' => __('backup.type.'.$this->type->value),
            // `reason` is a stable key (the step that failed); the title is localized prose, so
            // the frontend branches on the key only.
            'reason_title' => $this->reasonTitle(),
            // Where a run that died without reporting had got to, and the likely why. Null on
            // every other row, and on rows closed before these were recorded.
            'stopped_step' => $this->stopped_step,
            'stopped_cause' => $this->stopped_cause,
            // Why the storage refused, in the destination test's categories
            // (`invalid_credentials` → "The access key or secret was rejected."). Null when the
            // step was not a storage one or the cause is unknown.
            'error_class' => $this->error_class,
            'error_class_title' => $this->error_class === null ? null : __('storage.test.'.$this->error_class),
            'size_bytes' => $this->size_bytes,

            // Upload progress. `size_bytes` is only set once a backup has finished, so these tell a
            // healthy transfer from a dead socket. Sent raw, not as a percentage: the total can be
            // null, and computing `0` from it would show a progress bar that says nothing happens.
            'bytes_transferred' => $this->bytes_transferred,
            'bytes_total' => $this->bytes_total,

            // The heartbeat: "moving" and "stuck" look identical in a byte count sampled once.
            'progress_at' => $this->progress_at?->format('d-m-Y H:i:s'),
            'reason' => $this->reason,
            // No `log_key`: it addressed the server-ops log, which no screen opens. `reference`
            // finds the same failure in Admin → Error log.
            'reference' => $this->reference,
            'started_at' => $this->started_at?->format('d-m-Y H:i:s'),
            'finished_at' => $this->finished_at?->format('d-m-Y H:i:s'),
            'verified_at' => $this->verified_at?->format('d-m-Y H:i:s'),
            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }

    /**
     * A run that died without reporting (`abandoned` / `crashed`) says where it stopped, the
     * likely why, and what to do. Rows closed before the step was recorded keep their old sentence.
     */
    private function reasonTitle(): ?string
    {
        if ($this->reason === null) {
            return null;
        }

        $diedUnreported = in_array($this->reason, ['abandoned', 'crashed'], true);

        if (! $diedUnreported || ($this->stopped_step === null && $this->stopped_cause === null)) {
            return __('backup.errors.'.$this->reason);
        }

        return implode(' ', [
            $this->stopped_step === null
                ? __('backup.errors.stopped_before_steps')
                : __('backup.errors.stopped_at', ['step' => __('backup.steps.'.$this->stopped_step)]),
            $this->stopped_cause === null
                ? __('backup.errors.stopped_unknown')
                : __('backup.errors.stopped_cause.'.$this->stopped_cause),
            __('backup.errors.stopped_retry'),
        ]);
    }
}
