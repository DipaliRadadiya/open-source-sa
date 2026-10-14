<?php

namespace App\Http\Requests\Server\Database;

use App\Services\Server\Databases\Imports\ImportFiles;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Start an upload for an import. Only the name and the size: what the file
 * *is* is decided from its bytes once it has arrived, never from its name.
 */
class BeginDatabaseImportRequest extends FormRequest
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
            // Shown back to the user and nothing else — never a path.
            'file_name' => ['required', 'string', 'max:255'],
            'size_bytes' => ['required', 'integer', 'min:1', 'max:'.ImportFiles::maxBytes()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'size_bytes.max' => __('errors/database.import_too_large', [
                'max' => round(ImportFiles::maxBytes() / 1024 / 1024 / 1024, 1).' GB',
            ]),
        ];
    }
}
