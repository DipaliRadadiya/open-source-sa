<?php

namespace App\Http\Requests\Server\Backup;

use App\Enums\BackupType;
use App\Models\Application;
use App\Models\BackupTarget;
use App\Rules\BackupTypeForSite;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
        $application = $this->route('application');

        return [
            'storage_destination_id' => ['required', 'integer', Rule::exists('storage_destinations', 'id')],

            // `BackupTypeForSite` as well as the enum: the enum says the value
            // exists, the rule says it means something for *this* site. Without
            // it, every case the enum gains is silently accepted everywhere.
            'type' => array_filter([
                'required',
                Rule::enum(BackupType::class),
                $application instanceof Application ? new BackupTypeForSite($application) : null,
            ]),

            // At least one. Zero would mean every run prunes the backup it
            // just took, which reads as "backups silently do nothing".
            'retention_count' => ['required', 'integer', 'min:'.BackupTarget::RETENTION_MIN, 'max:'.BackupTarget::RETENTION_MAX],

            'frequency' => ['required', Rule::in(BackupTarget::FREQUENCIES)],
            'schedule_time' => ['sometimes', 'date_format:H:i'],
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

    /**
     * Bug #33: a Database backup was accepted for a site with no database
     * (n8n keeps its data in files), and every run then archived nothing.
     * Full and Files backups are unaffected.
     *
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $application = $this->route('application');

            if ($this->input('type') === BackupType::Database->value
                && $application instanceof Application
                && ! $application->databases()->exists()) {
                $validator->errors()->add('type', __('backup.errors.target_no_database', ['files' => __('backup.type.filesystem')]));
            }

        }];
    }
}
