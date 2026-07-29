<?php

namespace App\Http\Requests\Server\Fail2ban;

use App\Rules\IpOrCidr;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFail2banRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canManage('fail2ban');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `-1` is fail2ban's permanent ban; otherwise a minute is the
            // floor, since a shorter ban expires before it inconveniences
            // anyone and only looks like protection.
            'bantime' => ['required', 'integer', 'max:'.config('server.fail2ban.bantime_max'), function ($attribute, $value, $fail) {
                if ((int) $value !== -1 && (int) $value < 60) {
                    $fail('errors/fail2ban.bantime_too_short')->translate();
                }
            }],
            'findtime' => ['required', 'integer', 'min:30', 'max:86400'],
            // One failure is a typo, not an attack.
            'maxretry' => ['required', 'integer', 'min:2', 'max:100'],
            'ignore_ips' => ['sometimes', 'array', 'max:100'],
            'ignore_ips.*' => ['required', 'string', new IpOrCidr],
            'jails' => ['sometimes', 'array'],
            'jails.*' => ['boolean'],
            // Enabling the SSH jail can lock the operator out of their own
            // server; see Fail2banController for where this is required.
            'acknowledged' => ['sometimes', 'boolean'],
        ];
    }
}
