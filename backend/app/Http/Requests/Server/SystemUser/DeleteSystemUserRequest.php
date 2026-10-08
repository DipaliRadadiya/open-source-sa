<?php

namespace App\Http\Requests\Server\SystemUser;

use Illuminate\Foundation\Http\FormRequest;

class DeleteSystemUserRequest extends FormRequest
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
            // FS-C31: end the account's SSH sessions and processes first.
            'end_sessions' => ['sometimes', 'boolean'],
        ];
    }
}
