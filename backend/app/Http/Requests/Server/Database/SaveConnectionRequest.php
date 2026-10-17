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
            // Root's CURRENT password on a server that already has one (moved
            // from v7). Checked against the server before it is stored.
            'root_password' => ['sometimes', 'nullable', 'string', 'max:128', 'regex:/^[^\x00-\x1F\x7F]+$/'],
            // Optionally probe the connection after saving.
            'test' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'root_password.regex' => __('errors/database.root_password_characters'),
        ];
    }
}
