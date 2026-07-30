<?php

namespace App\Http\Requests\Server\Firewall;

use App\Rules\IpOrCidr;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFirewallRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canManage('firewall');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'port_from' => ['sometimes', 'integer', 'min:1', 'max:65534'],
            'port_to' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535', 'gte:port_from'],
            'protocol' => ['sometimes', Rule::in(['all', 'tcp', 'udp'])],
            'action' => ['sometimes', Rule::in(['allow', 'deny'])],
            'source_ip' => ['sometimes', 'nullable', 'string', new IpOrCidr],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Switching a rule off keeps it: testing whether a rule matters
            // should not mean deleting it and hoping it is retyped correctly.
            'enabled' => ['sometimes', 'boolean'],
        ];
    }
}
