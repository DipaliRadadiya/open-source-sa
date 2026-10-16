<?php

namespace App\Http\Requests\Server\Database;

use App\Models\BackupTarget;
use App\Models\Database;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The backup schedule of a database on its own (2026-10-16).
 *
 * The same fields as a site's target minus the ones that only mean something
 * for a site: the type is always `database`, and there are no file or
 * database excludes — there is one database and no files.
 */
class SaveDatabaseBackupTargetRequest extends FormRequest
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
            'storage_destination_id' => ['required', 'integer', Rule::exists('storage_destinations', 'id')],
            'retention_count' => ['required', 'integer', 'min:'.BackupTarget::RETENTION_MIN, 'max:'.BackupTarget::RETENTION_MAX],
            'frequency' => ['required', Rule::in(BackupTarget::FREQUENCIES)],
            'schedule_time' => ['sometimes', 'date_format:H:i'],
            'enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * A site's database is backed up with the site (decision 2026-10-16): a
     * second schedule beside the site's would dump the same database twice
     * and restore it from two places that disagree.
     *
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $database = $this->route('database');

            if ($database instanceof Database && $database->application_id !== null) {
                $validator->errors()->add('database', __('backup.errors.database_backed_up_with_site', [
                    'site' => (string) $database->application?->name,
                ]));
            }
        }];
    }
}
