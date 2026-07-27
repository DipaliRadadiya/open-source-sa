<?php

namespace App\Http\Requests\Server\Setting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SecuritySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('setting') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'permit_root_login' => ['required', Rule::in(['yes', 'no', 'prohibit-password'])],
            'password_authentication' => ['required', 'boolean'],
        ];
    }
}
