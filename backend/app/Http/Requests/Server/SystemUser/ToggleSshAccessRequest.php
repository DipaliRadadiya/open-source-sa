<?php

namespace App\Http\Requests\Server\SystemUser;

use App\Enums\LoginShell;
use App\Models\SystemUser;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ToggleSshAccessRequest extends FormRequest
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
            'ssh_access' => ['required', 'boolean'],
        ];
    }

    /**
     * Turning SSH on for an account whose shell refuses login would record
     * access the server will not honour — sshd authenticates, then the shell
     * exits and the session closes. Checked against the stored shell, since
     * this endpoint only carries the toggle.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->boolean('ssh_access')) {
                return;
            }

            $systemUser = $this->route('systemUser');

            if ($systemUser instanceof SystemUser && LoginShell::allowsLoginFor($systemUser->shell) === false) {
                $validator->errors()->add('ssh_access', __('errors/system-user.ssh_needs_login_shell'));
            }
        });
    }
}
