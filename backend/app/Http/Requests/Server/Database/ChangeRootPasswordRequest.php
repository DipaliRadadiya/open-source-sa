<?php

namespace App\Http\Requests\Server\Database;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A new MySQL/MariaDB root password.
 *
 * Quotes and spaces are allowed, unlike v7: v7 built shell commands out of it,
 * and here it only ever travels over stdin as an escaped SQL literal. Control
 * characters are not — a newline cannot be typed back into a client prompt.
 *
 * `current_password` is optional, and used only when the panel cannot reach
 * root any other way (RC-26): a server moved from v7 whose root has a password
 * the panel never stored. It goes into a 0600 option file for that one sign-in
 * and is never stored or logged. No control characters there either: a line
 * break would end the option file's password line and start another option.
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
            'current_password' => ['nullable', 'string', 'max:128', 'regex:/^[^\x00-\x1F\x7F]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.regex' => __('errors/database.root_password_characters'),
            'current_password.regex' => __('errors/database.root_password_characters'),
        ];
    }

    public function password(): string
    {
        return (string) $this->validated('password');
    }

    public function currentPassword(): ?string
    {
        $current = $this->validated('current_password');

        return is_string($current) && $current !== '' ? $current : null;
    }
}
