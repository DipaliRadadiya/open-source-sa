<?php

namespace App\Http\Requests\Server\Firewall;

use App\Models\FirewallRule;
use App\Rules\IpOrCidr;
use App\Rules\SingleLine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFirewallRuleRequest extends FormRequest
{
    use RefusesDuplicateRules;

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
            'protocol' => ['required', Rule::in(['all', 'tcp', 'udp']), $this->rangeNeedsOneProtocol()],
            'action' => ['required', Rule::in(['allow', 'deny'])],
            'source_ip' => ['nullable', 'string', new IpOrCidr],
            'description' => ['nullable', 'string', 'max:255', new SingleLine],
        ];
    }

    /**
     * ufw refuses a port range without a protocol ("Must specify 'tcp' or
     * 'udp' with multiple ports"), and the panel answered that with a 500
     * (frontend QA FS-C22). Refused here with a reason instead.
     */
    private function rangeNeedsOneProtocol(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $from = $this->input('port_from');
            $to = $this->input('port_to');

            if ($value === 'all' && $to !== null && (int) $to !== (int) $from) {
                $fail(__('errors/firewall.range_needs_protocol'));
            }
        };
    }
}
