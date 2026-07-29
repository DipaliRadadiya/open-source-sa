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
            // A ban shorter than the window it is measured over bans nobody
            // for any useful length of time, so the floor is a minute rather
            // than zero.
            'bantime' => ['required', 'integer', 'min:60', 'max:'.config('server.fail2ban.bantime_max')],
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
