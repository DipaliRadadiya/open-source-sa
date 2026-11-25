<?php

namespace App\Http\Requests\Server\Backup;

use App\Enums\BackupType;
use App\Models\Application;
use App\Models\BackupTarget;
use App\Rules\BackupTypeForSite;
use App\Services\Server\Docker\VolumeSizes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create or update the backup settings for one application.
 *
 * One request for both: a target is one row per application (unique on `application_id`), so
 * create and update are the same act for the user.
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

            // `BackupTypeForSite` as well as the enum: the enum says the value exists, the rule
            // says it means something for *this* site.
            'type' => array_filter([
                'required',
                Rule::enum(BackupType::class),
                $application instanceof Application ? new BackupTypeForSite($application) : null,
            ]),

            // At least one: zero would prune the backup each run just took.
            'retention_count' => ['required', 'integer', 'min:'.BackupTarget::RETENTION_MIN, 'max:'.BackupTarget::RETENTION_MAX],

            'frequency' => ['required', Rule::in(BackupTarget::FREQUENCIES)],
            'schedule_time' => ['sometimes', 'date_format:H:i'],
            'enabled' => ['required', 'boolean'],

            // Exclusions are tar patterns and database names, never paths the panel resolves. They
            // go into argv as their own elements, not into a shell string.
            'file_excludes' => ['sometimes', 'array', 'max:100'],
            'file_excludes.*' => ['string', 'max:255'],
            'database_excludes' => ['sometimes', 'array', 'max:100'],
            'database_excludes.*' => ['string', 'max:64'],
        ];
    }

    /**
     * Refuses a Database backup for a site with no database (every run would archive nothing).
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

            // Same for `volumes` on a site with no volumes. Only once the type is otherwise
            // valid: `BackupTypeForSite` already refused it on a hosted site, and asking Docker
            // there would add a second message.
            if ($this->input('type') === BackupType::Volumes->value
                && $application instanceof Application
                && ! $validator->errors()->has('type')
                && app(VolumeSizes::class)->hasNone($application)) {
                $validator->errors()->add('type', __('backup.errors.target_no_volumes'));
            }
        }];
    }
}
