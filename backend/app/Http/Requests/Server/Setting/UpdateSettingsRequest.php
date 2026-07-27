<?php

namespace App\Http\Requests\Server\Setting;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
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
            'security_updates_enabled' => ['required', 'boolean'],
            'auto_reboot' => ['required', 'boolean'],
            // "HH:MM" (24h) or the literal "now".
            'reboot_time' => ['required', 'string', 'regex:/^(now|([01]\d|2[0-3]):[0-5]\d)$/'],
        ];
    }
}
