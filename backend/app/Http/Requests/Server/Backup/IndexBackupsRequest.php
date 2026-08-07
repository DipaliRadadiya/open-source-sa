<?php

namespace App\Http\Requests\Server\Backup;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for the cross-application restore list.
 *
 * The route already gates on `permission:backup`; this exists to validate the
 * query, which was previously read straight off the request. `filter[status]`
 * in particular accepted any string and silently returned an empty list for a
 * typo, which reads to the user as "there are no backups".
 */
class IndexBackupsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canView('backup') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'filter.application_id' => ['sometimes', 'integer', Rule::exists('applications', 'id')],
            'filter.status' => ['sometimes', Rule::enum(BackupStatus::class)],
            'filter.type' => ['sometimes', Rule::enum(BackupType::class)],

            // Bounds on the run date. Inclusive at both ends — see the
            // controller for why `to` becomes end-of-day.
            'filter.from' => ['sometimes', 'date'],
            'filter.to' => ['sometimes', 'date', 'after_or_equal:filter.from'],

            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
