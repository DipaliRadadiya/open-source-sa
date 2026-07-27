<?php

namespace App\Http\Requests\Server\Setting;

use Illuminate\Foundation\Http\FormRequest;

class RebootServerRequest extends FormRequest
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
            // Optional grace window (minutes) before the reboot; default = now.
            'delay_minutes' => ['sometimes', 'integer', 'min:0', 'max:60'],
        ];
    }
}
