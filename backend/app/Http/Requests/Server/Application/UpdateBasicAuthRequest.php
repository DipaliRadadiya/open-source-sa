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
            // Both required when turning protection on or changing the credential: no partial update.
            'username' => [
                Rule::requiredIf($this->boolean('enabled')),
                'string',
                'max:255',
                // The htpasswd line is `username:hash`: a colon would corrupt it, and nothing
                // downstream re-validates. Printable ASCII only: browsers send Basic Auth in an
                // unspecified encoding, so an accented or non-Latin name would never match.
                'regex:/^[\x21-\x39\x3B-\x7E]+$/',
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
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.regex' => __('validation.basic_auth_username'),
        ];
    }

    /**
     * Refuse to put Basic Auth in front of an application that authenticates
     * with the `Authorization` header.
     *
     * HTTP carries one `Authorization` header: with the application's own `Bearer` token, nginx
     * answers 401; with the Basic credentials, the application does. It becomes unusable, so this
     * is rejected up front. These applications still mandate their own credentials at install.
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
