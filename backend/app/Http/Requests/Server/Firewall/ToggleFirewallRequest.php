<?php

namespace App\Http\Requests\Server\Firewall;

use Illuminate\Foundation\Http\FormRequest;

class ToggleFirewallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('firewall') ?? false;
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
}
