<?php

namespace App\Http\Requests\Server\SystemUser;

use App\Enums\LoginShell;
use App\Models\SystemUser;
use App\Rules\InstalledShell;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ChangeShellRequest extends FormRequest
{
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
            'shell' => ['required', 'string', new InstalledShell],
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
