<?php

namespace App\Http\Requests\Server\Firewall;

use App\Models\FirewallRule;
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
            'port_from' => ['required', 'integer', 'between:'.FirewallRule::PORT_MIN.','.FirewallRule::PORT_MAX],
            'port_to' => ['nullable', 'integer', 'between:'.FirewallRule::PORT_MIN.','.FirewallRule::PORT_MAX, 'gte:port_from'],
            'protocol' => ['required', Rule::in(['all', 'tcp', 'udp'])],
            'action' => ['required', Rule::in(['allow', 'deny'])],
            'source_ip' => ['nullable', 'string', new IpOrCidr],
            'description' => ['nullable', 'string', 'max:255', new SingleLine],
        ];
    }
}
