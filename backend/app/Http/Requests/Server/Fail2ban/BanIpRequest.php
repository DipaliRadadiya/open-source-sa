<?php

namespace App\Http\Requests\Server\Fail2ban;

use App\Support\IpAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BanIpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canManage('fail2ban');
    }

    /**
     * The address fail2ban will really ban, before anything checks it
     * (F2B-01): `::ffff:1.2.3.4` is 1.2.3.4 to fail2ban, and was a different
     * string to every guard.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('ip'))) {
            $this->merge(['ip' => IpAddress::canonical($this->input('ip'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A single address, not a range: fail2ban's banip takes one host,
            // and a mistyped CIDR here could ban a whole network.
            'ip' => ['required', 'ip'],
            'jail' => ['required', 'string', Rule::in(array_column((array) config('server.fail2ban.jails', []), 'name'))],
        ];
    }
}
