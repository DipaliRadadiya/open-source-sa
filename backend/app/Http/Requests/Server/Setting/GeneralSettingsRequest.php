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
            // What the kernel will actually keep: 64 characters at most
            // (HOST_NAME_MAX), dot-separated labels of 1–63 letters, digits
            // and inner hyphens. The old rule allowed 253 characters and any
            // run of dots, so `a..b` and a 70-character name were accepted:
            // the kernel cut the long one to 64 while /etc/hosts got all 70,
            // and the server's own name stopped resolving (Apache test box).
            'hostname' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/'],
            'ntp' => ['required', 'boolean'],
        ];
    }
}
