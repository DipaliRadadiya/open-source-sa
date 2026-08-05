<?php

namespace App\Http\Requests\Server\Application;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFail2banRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('app_fail2ban') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }

    public function enabled(): bool
    {
        return $this->boolean('enabled');
    }
}
