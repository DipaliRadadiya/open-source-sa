<?php

namespace App\Http\Requests\Server\SystemUser;

use App\Enums\LoginShell;
use App\Models\SystemUser;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeShellRequest extends FormRequest
{
    /**
     * Allowlisted login shells — user input never picks an arbitrary shell.
     *
     * Derived from the enum rather than repeated, so the list the API
     * publishes and the list it accepts cannot drift apart.
     *
     * @return array<int, string>
     */
    public static function shells(): array
    {
        return LoginShell::paths();
    }

    public function authorize(): bool
    {
        return $this->user()?->canManage('system_user') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'shell' => ['required', 'string', Rule::in(self::shells())],
        ];
    }

    /**
     * The same contradiction approached from the other side. Refusing rather
     * than quietly switching SSH access off keeps the rule symmetrical: the
     * panel never changes a setting the user did not touch.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $systemUser = $this->route('systemUser');

            if (! $systemUser instanceof SystemUser || ! $systemUser->ssh_access) {
                return;
            }

            if (LoginShell::allowsLoginFor($this->input('shell')) === false) {
                $validator->errors()->add('shell', __('errors/system-user.shell_needs_ssh_off'));
            }
        });
    }
}
