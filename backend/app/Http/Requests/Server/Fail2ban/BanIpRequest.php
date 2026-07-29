<?php

namespace App\Http\Requests\Server\Fail2ban;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BanIpRequest extends FormRequest
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
            // A single address, not a range: fail2ban's banip takes one host,
            // and a mistyped CIDR here could ban a whole network.
            'ip' => ['required', 'ip'],
            'jail' => ['required', 'string', Rule::in(array_column((array) config('server.fail2ban.jails', []), 'name'))],
        ];
    }
}
