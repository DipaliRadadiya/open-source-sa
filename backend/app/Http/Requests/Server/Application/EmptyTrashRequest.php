<?php

namespace App\Http\Requests\Server\Application;

use Illuminate\Foundation\Http\FormRequest;

class EmptyTrashRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('app_file') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Omitted empties everything; given, only that batch. Confirming
            // is the caller's job either way — this is the one file-manager
            // action with nothing behind it.
            'batch' => ['sometimes', 'nullable', 'string', 'regex:/^\d{8}-\d{6}$/'],
            'confirm' => ['required', 'accepted'],
        ];
    }

    public function batch(): ?string
    {
        return $this->validated('batch') ?: null;
    }
}
