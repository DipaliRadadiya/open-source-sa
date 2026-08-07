<?php

namespace App\Http\Requests\Server\Restore;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Enums\RestoreStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for the cross-application restore list.
 *
 * The route already gates on `permission:backup`; this validates the query
 * which was previously read straight off the request. `filter[status]`
 * accepted any string and silently returned an empty list for a typo,
 * which reads to the user as "there are no restores".
 */
class IndexRestoresRequest extends FormRequest
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
            'filter.status' => ['sometimes', Rule::enum(RestoreStatus::class)],
            'filter.type' => ['sometimes', Rule::enum(BackupType::class)],

            // Bounds on the run date.
            'filter.from' => ['sometimes', 'date'],
            'filter.to' => ['sometimes', 'date', 'after_or_equal:filter.from'],

            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
