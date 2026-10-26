<?php

namespace App\Http\Requests\Server\Database;

use App\Models\DatabaseExport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportDatabaseRequest extends FormRequest
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
            // Days until the export deletes itself. Absent or null = kept until
            // the user deletes it (Dipali, 2026-10-26: the default).
            'keep_days' => ['nullable', 'integer', Rule::in(DatabaseExport::KEEP_DAYS)],
        ];
    }
}
