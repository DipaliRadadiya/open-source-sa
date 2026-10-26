<?php

namespace App\Http\Requests\Server\Database;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Run an uploaded import. `confirm_replace` is required, not decorative: the
 * import replaces everything in the database, and the API refuses to do that
 * for a client that did not show and confirm the warning.
 */
class StartDatabaseImportRequest extends FormRequest
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
            'confirm_replace' => ['accepted'],
            // Copy the database first so a failed import can be put back.
            // Absent = yes, as every import did before it could be skipped.
            'safety_copy' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirm_replace.accepted' => __('errors/database.import_confirm_required'),
        ];
    }
}
