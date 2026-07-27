<?php

namespace App\Http\Requests\Server\Firewall;

use App\Rules\IpOrCidr;
use App\Rules\SingleLine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFirewallRuleRequest extends FormRequest
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
            'port_from' => ['required', 'integer', 'between:1,65534'],
            'port_to' => ['nullable', 'integer', 'between:1,65534', 'gte:port_from'],
            'protocol' => ['required', Rule::in(['all', 'tcp', 'udp'])],
            'action' => ['required', Rule::in(['allow', 'deny'])],
            'source_ip' => ['nullable', 'string', new IpOrCidr],
            'description' => ['nullable', 'string', 'max:255', new SingleLine],
        ];
    }
}
