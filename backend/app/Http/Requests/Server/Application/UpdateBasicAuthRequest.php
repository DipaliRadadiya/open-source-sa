<?php

namespace App\Http\Requests\Server\Application;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBasicAuthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('app_security') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            // Both required whenever turning protection on, or changing the
            // credential on a site already protected — one save action, no
            // partial "keep the old password" update to reason about.
            'username' => [
                Rule::requiredIf($this->boolean('enabled')),
                'string',
                'max:255',
                // The htpasswd line is `username:hash` — a colon in the
                // username would corrupt it, and this is the one place
                // nothing downstream re-validates that.
                'regex:/^[^:\s]+$/',
            ],
            'password' => [
                Rule::requiredIf($this->boolean('enabled')),
                'string',
                'min:8',
                'max:255',
            ],
        ];
    }

    public function enabled(): bool
    {
        return $this->boolean('enabled');
    }

    public function username(): string
    {
        return (string) $this->validated('username');
    }

    public function password(): string
    {
        return (string) $this->validated('password');
    }
}
