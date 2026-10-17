<?php

namespace App\Http\Requests\Server\Database;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A new MySQL/MariaDB root password.
 *
 * Quotes and spaces are allowed, unlike v7: v7 built shell commands out of it,
 * and here it only ever travels over stdin as an escaped SQL literal. Control
 * characters are not — a newline cannot be typed back into a client prompt.
 */
class ChangeRootPasswordRequest extends FormRequest
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
            'password' => ['required', 'string', 'min:12', 'max:128', 'regex:/^[^\x00-\x1F\x7F]+$/', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.regex' => __('errors/database.root_password_characters'),
        ];
    }

    public function password(): string
    {
        return (string) $this->validated('password');
    }
}
