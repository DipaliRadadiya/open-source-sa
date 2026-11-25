<?php

namespace App\Http\Requests\Server\Backup;

use App\Enums\BackupStatus;
use App\Enums\RestoreStatus;
use App\Models\Backup;
use App\Models\Restore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RestoreBackupRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route also carries `permission:backup,manage`; kept here so a route added later
        // without the middleware still cannot restore.
        return $this->user()?->canManage('backup') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', Rule::in(['filesystem', 'database', 'full'])],
            // Typed, not clicked: the guard against the person going wrong.
            'confirm' => ['required', 'string'],
            // Copy the application first so the restore can be undone; on unless turned off.
            'safety_copy' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Backup $backup */
            $backup = $this->route('backup');

            if ($backup->status !== BackupStatus::Verified) {
                // An unverified backup could not be proven intact; restoring it could overwrite a
                // working site with a bad copy.
                $validator->errors()->add('backup', __('backup.errors.restore_unverified'));

                return;
            }

            if ($backup->isDatabaseBackup()) {
                // A database's own backup is restored from the database's page; see
                // RestoreDatabaseBackupRequest.
                $validator->errors()->add('backup', __('backup.errors.restore_database_backup_here'));

                return;
            }

            if ($backup->isArchived()) {
                // A deleted site's backup: there is no site to restore into; it can be downloaded.
                $validator->errors()->add('backup', __('backup.errors.restore_archived_site'));

                return;
            }

            if ($backup->application === null) {
                $validator->errors()->add('backup', __('backup.errors.restore_no_application'));

                return;
            }

            if ($this->string('confirm')->trim()->value() !== $backup->application->domain) {
                $validator->errors()->add('confirm', __('backup.errors.restore_confirm'));
            }

            $inFlight = Restore::query()
                ->where('application_id', $backup->application_id)
                ->whereIn('status', [RestoreStatus::Pending->value, RestoreStatus::Running->value])
                ->exists();

            if ($inFlight) {
                $validator->errors()->add('backup', __('backup.errors.restore_already_running'));
            }

            // What was asked for must be a subset of what the archive holds; `full` from a
            // database-only backup would swap an empty directory over a working site.
            $requested = $this->requestedType();
            $wantsFiles = in_array($requested, ['filesystem', 'full'], true);
            $wantsDatabase = in_array($requested, ['database', 'full'], true);
            $hasFiles = in_array($backup->type->value, ['filesystem', 'full'], true);
            // What the archive holds, not the type: a site with no database has a `full` backup
            // with no dump.
            $hasDatabase = $backup->holdsDatabase();

            if ($wantsDatabase && ! $hasDatabase) {
                $validator->errors()->add('type', __('backup.errors.restore_no_database'));
            }

            if ($wantsFiles && ! $hasFiles) {
                $validator->errors()->add('type', __('backup.errors.restore_no_files'));
            }
        });
    }

    public function safetyCopy(): bool
    {
        return $this->boolean('safety_copy', true);
    }

    public function requestedType(): string
    {
        /** @var Backup $backup */
        $backup = $this->route('backup');

        $asked = $this->string('type')->trim()->value();

        if ($asked !== '') {
            return $asked;
        }

        // Not asked: default to what the backup holds (`full` with no database is just files).
        return $backup->type->value === 'full' && ! $backup->holdsDatabase()
            ? 'filesystem'
            : $backup->type->value;
    }
}
