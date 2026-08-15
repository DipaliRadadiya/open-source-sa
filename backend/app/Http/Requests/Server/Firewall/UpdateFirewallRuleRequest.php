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
     * `gte:port_from` only fires when `port_to` is in the request. Sending
     * `port_from` alone therefore compared it against nothing, and a rule
     * stored as 9000-9100 could be patched to start at 9500 — a backwards
     * range. On an enabled rule ufw rejects it; on a disabled one nothing runs,
     * so it sat in the database until someone switched the rule on. Filling in
     * the stored values makes the rule compare what will actually be saved.
     */
    protected function prepareForValidation(): void
    {
        $rule = $this->route('firewallRule');

        if (! $rule) {
            return;
        }

        $this->merge([
            'port_from' => $this->input('port_from', $rule->port_from),
            'port_to' => $this->has('port_to') ? $this->input('port_to') : $rule->port_to,
        ]);
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
