<?php

namespace App\Http\Requests\Server\Firewall;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A rule ufw enforces that the panel has no row for, named by the `key` the
 * Firewall screen lists it under (FW-08). The key is matched against what ufw
 * says now, never trusted as a rule on its own.
 */
class UnmanagedFirewallRuleRequest extends FormRequest
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
            'key' => ['required', 'string', 'max:255'],
        ];
    }

    public function key(): string
    {
        return (string) $this->validated('key');
    }
}
