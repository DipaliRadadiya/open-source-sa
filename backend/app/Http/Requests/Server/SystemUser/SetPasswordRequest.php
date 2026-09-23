<?php

namespace App\Http\Requests\Server\SystemUser;

use App\Services\Server\SystemUsers\ChpasswdLine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class SetPasswordRequest extends FormRequest
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
            'password' => ['required', 'string', Password::defaults(), 'not_regex:'.ChpasswdLine::FORBIDDEN],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // chpasswd reads one account per line — see ChpasswdLine.
            'password.not_regex' => __('errors/system-user.password_control_characters'),
        ];
    }
}
