<?php

namespace App\Http\Requests\Server\Database;

use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\Database;
use App\Models\DatabaseExport;
use App\Models\DatabaseImport;
use App\Models\Restore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Restore a database backup into the database in the URL.
 *
 * Its own backup, or an archived one — of a database since deleted — of the
 * same engine. Everything the restore will need is checked here, before
 * anything is queued: the database is replaced, and a safety backup through
 * its own schedule is the only way back.
 */
class RestoreDatabaseBackupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('database') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Typed, not clicked — the database's name, as a site restore
            // asks for the domain.
            'confirm' => ['required', 'string'],
            // Back the database up before replacing it, so the restore can be
            // undone. The user's choice since 2026-10-29; on by default.
            'safety_copy' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Database $database */
            $database = $this->route('database');
            /** @var Backup $backup */
            $backup = $this->route('backup');

            if ($backup->status !== BackupStatus::Verified) {
                $validator->errors()->add('backup', __('backup.errors.restore_unverified'));

                return;
            }

            $ownBackup = $backup->database_id === $database->id;

            if (! $backup->isDatabaseBackup() || (! $ownBackup && ! $backup->isArchived())) {
                $validator->errors()->add('backup', __('backup.errors.restore_wrong_database'));

                return;
            }

            if ($backup->subject_engine !== $database->engine) {
                $validator->errors()->add('backup', __('backup.errors.restore_engine_mismatch', [
                    'backup' => (string) $backup->subject_engine,
                    'database' => $database->engine,
                ]));

                return;
            }

            // The safety backup goes through this database's own schedule. A
            // site's database has none (it is backed up with the site), and a
            // database never set up has none either.
            if ($database->backupTarget === null) {
                $validator->errors()->add('database', __('backup.errors.restore_needs_target'));

                return;
            }

            if ($this->string('confirm')->trim()->value() !== $database->name) {
                $validator->errors()->add('confirm', __('backup.errors.restore_confirm_database'));
            }

            $busy = Restore::inProgressForDatabase($database->id)
                || DatabaseImport::query()->where('database_id', $database->id)->open()->exists()
                || DatabaseExport::query()->where('database_id', $database->id)->inFlight()->get()->contains(fn ($e) => ! $e->isStale());

            if ($busy) {
                $validator->errors()->add('backup', __('backup.errors.restore_database_busy'));
            }
        });
    }

    public function safetyCopy(): bool
    {
        return $this->boolean('safety_copy', true);
    }
}
