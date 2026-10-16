<?php

namespace App\Http\Requests\Server\Fail2ban;

use App\Rules\IpOrCidr;
use App\Support\IpAddress;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One entry on the ignore list, added or removed on its own (v7's
 * ignore-ip add/remove), for both `POST` and `DELETE /fail2ban/ignore-ips`.
 */
class IgnoreIpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canManage('fail2ban');
    }

    /**
     * The address in the form fail2ban uses (F2B-01), so `::ffff:1.2.3.4`
     * cannot sit on the list beside `1.2.3.4` as a second, different string.
     * A range keeps its mask.
     */
    protected function prepareForValidation(): void
    {
        $ip = $this->input('ip');

        if (is_string($ip) && $ip !== '') {
            [$address, $mask] = array_pad(explode('/', trim($ip), 2), 2, null);
            $this->merge(['ip' => IpAddress::canonical($address).($mask === null ? '' : '/'.$mask)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ip' => ['required', 'string', new IpOrCidr],
            // Removing your own address while the SSH jail runs; see
            // Fail2banController::removeIgnoreIp.
            'acknowledged' => ['sometimes', 'boolean'],
        ];
    }
}
