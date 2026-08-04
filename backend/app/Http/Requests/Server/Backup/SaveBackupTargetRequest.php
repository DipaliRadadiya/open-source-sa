<?php

namespace App\Http\Requests\Server\Backup;

use App\Models\BackupTarget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update the backup settings for one application.
 *
 * One request for both: a target is one row per application (the table has a
 * unique on `application_id`), so "create" and "update" are the same act from
 * the user's side — they are configuring backups for this site.
 */
class SaveBackupTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('app_backup') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'storage_destination_id' => ['required', 'integer', Rule::exists('storage_destinations', 'id')],
            'type' => ['required', Rule::in(['filesystem', 'database', 'full'])],

            // At least one. Zero would mean every run prunes the backup it
            // just took, which reads as "backups silently do nothing".
            'retention_count' => ['required', 'integer', 'min:1', 'max:365'],

            'frequency' => ['required', Rule::in(BackupTarget::FREQUENCIES)],
            'enabled' => ['required', 'boolean'],

            // Exclusions are tar patterns and database names — never paths the
            // panel resolves, so there is nothing here to escape with. They go
            // into argv as their own elements, not into a shell string.
            'file_excludes' => ['sometimes', 'array', 'max:100'],
            'file_excludes.*' => ['string', 'max:255'],
            'database_excludes' => ['sometimes', 'array', 'max:100'],
            'database_excludes.*' => ['string', 'max:64'],
        ];
    }
}
