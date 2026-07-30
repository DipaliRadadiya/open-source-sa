<?php

namespace App\Http\Requests\Server\Setting;

use App\Services\Timezones;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GeneralSettingsRequest extends FormRequest
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
            // Checked against the same list the picker is built from, which
            // is the OS's own — because the value is handed to
            // `timedatectl set-timezone`, so the OS decides. Validating
            // against PHP's default identifier list rejected `Etc/UTC`, the
            // timezone a fresh Debian box is actually set to, which made the
            // form unsavable until you changed a field you had not come to
            // change.
            'timezone' => ['required', 'string', Rule::in(app(Timezones::class)->identifiers())],
            'hostname' => ['required', 'string', 'max:253', 'regex:/^[a-zA-Z0-9]([a-zA-Z0-9\-\.]{0,251}[a-zA-Z0-9])?$/'],
            'ntp' => ['required', 'boolean'],
        ];
    }
}
