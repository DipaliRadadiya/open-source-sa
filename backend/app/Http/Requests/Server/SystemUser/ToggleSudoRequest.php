<?php

namespace App\Http\Requests\Server\SystemUser;

use Illuminate\Foundation\Http\FormRequest;

class ToggleSudoRequest extends FormRequest
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
            'sudo' => ['required', 'boolean'],
        ];
    }
}
