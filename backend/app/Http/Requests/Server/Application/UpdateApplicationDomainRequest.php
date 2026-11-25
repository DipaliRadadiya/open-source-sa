<?php

namespace App\Http\Requests\Server\Application;

use App\Enums\DomainType;
use App\Models\ApplicationDomain;
use App\Models\Certificate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Change what an attached name *does*, never what it is called.
 *
 * `domain` is deliberately absent: a rename would leave the old name on any Let's Encrypt
 * certificate, `certbot renew` would fail the whole lineage, and the certificate's remaining
 * names would stop renewing. {@see Certificate::staleDomains()}. Remove and re-add instead.
 *
 * `primary` is absent too: promoting a name renames the vhost and log files, which
 * `makePrimary()` handles.
 */
class UpdateApplicationDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canManage('app_domain');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', Rule::in([DomainType::Alias->value, DomainType::Redirect->value])],
            // No `required_if`: the row may already be a redirect and the request may change only
            // the target, so the effective type is resolved against the stored row in the action.
            'redirect_to' => ['nullable', 'string', 'max:2048', 'url', 'regex:'.ApplicationDomain::REDIRECT_TARGET_PATTERN],
            'redirect_status' => ['sometimes', Rule::in([301, 302, 307, 308])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'redirect_to.regex' => __('errors/application.redirect_target_invalid'),
        ];
    }

    protected function prepareForValidation(): void
    {
        // No trailing slash: every vhost appends the request path, which brings its own.
        if (is_string($this->input('redirect_to'))) {
            $this->merge(['redirect_to' => rtrim(trim($this->input('redirect_to')), '/')]);
        }
    }
}
