<?php

namespace App\Http\Requests\Server\Application;

use Illuminate\Foundation\Http\FormRequest;

class BanApplicationIpRequest extends FormRequest
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
            'ip' => ['required', 'ip'],
        ];
    }

    public function ip(): string
    {
        return (string) $this->validated('ip');
    }
}
