<?php

namespace App\Http\Requests\Server\Application;

use App\Models\Application;
use App\Services\Applications\SiteTypeManager;
use Illuminate\Contracts\Validation\Validator;
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

    /**
     * Refuse to put Basic Auth in front of an application that authenticates
     * with the `Authorization` header.
     *
     * HTTP carries one `Authorization` header. If the application's own client
     * sends `Authorization: Bearer <token>`, that replaces the Basic
     * credentials the browser would attach and nginx answers 401; send the
     * credentials instead and the application answers 401. The application
     * becomes unusable rather than merely double-protected, so this is
     * rejected at the boundary rather than left to be discovered.
     *
     * These applications are not left unprotected: every type that returns
     * true here mandates its own credentials at install time.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->boolean('enabled')) {
                return;
            }

            $application = $this->route('application');

            if (! $application instanceof Application) {
                return;
            }

            $type = app(SiteTypeManager::class)->find((string) $application->site_type);

            if ($type?->authorizationHeaderAuth() !== true) {
                return;
            }

            $validator->errors()->add('enabled', __('validation.basic_auth_conflicts', [
                'type' => $application->site_type,
            ]));
        });
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
