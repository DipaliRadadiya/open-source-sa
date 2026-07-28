<?php

namespace App\Http\Requests\Server\Database;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveConnectionRequest extends FormRequest
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
            'connection_type' => ['required', Rule::in(['tcp', 'socket'])],
            'host' => [Rule::requiredIf(fn () => $this->input('connection_type') === 'tcp'), 'nullable', 'string', 'max:255'],
            'port' => [Rule::requiredIf(fn () => $this->input('connection_type') === 'tcp'), 'nullable', 'integer', 'between:1,65535'],
            'socket' => [Rule::requiredIf(fn () => $this->input('connection_type') === 'socket'), 'nullable', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'options' => ['nullable', 'array'],
            // Optionally probe the connection after saving.
            'test' => ['sometimes', 'boolean'],
        ];
    }
}
