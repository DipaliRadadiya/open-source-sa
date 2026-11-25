<?php

namespace App\Http\Requests\Server\Application;

use App\Enums\DomainType;
use App\Models\ApplicationDomain;
use App\Rules\Hostname;
use App\Rules\NotPanelHost;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApplicationDomainRequest extends FormRequest
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
            // Unique across every application: with two sites on one hostname the web server
            // serves whichever vhost it reads first.
            //
            // The charset is strict because this value ends up in a config filename and inside
            // server_name; a path separator or directive break is refused here, not escaped later.
            'domain' => [
                'required', 'string', 'max:253',
                // Each label, and a real top-level domain, never an IP. Shared with create,
                // staging and clone.
                new Hostname,
                Rule::unique('application_domains', 'domain'),
                new NotPanelHost,
            ],
            // Primary is not settable here — promoting a name is its own
            // action, because it renames the vhost and both log files.
            'type' => ['sometimes', Rule::in([DomainType::Alias->value, DomainType::Redirect->value])],
            'redirect_to' => ['nullable', 'required_if:type,redirect', 'string', 'max:2048', 'url', 'regex:'.ApplicationDomain::REDIRECT_TARGET_PATTERN],
            'redirect_status' => ['sometimes', Rule::in([301, 302, 307, 308])],
        ];
    }

    /**
     * Name the application already holding it.
     *
     * The default "already been taken" message does not say which site holds the name.
     *
     * No leak: `app_domain` is a server-wide permission and applications are not scoped per
     * user, so anyone reaching this endpoint can already list every application.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $holder = ApplicationDomain::query()
            ->where('domain', strtolower(trim((string) $this->input('domain'))))
            ->with('application')
            ->first()?->application;

        return [
            'redirect_to.regex' => __('errors/application.redirect_target_invalid'),
            // Plain message when the holder is gone (row deleted since the check).
            'domain.unique' => $holder === null
                ? __('errors/application.domain_taken')
                : __('errors/application.domain_taken_by', ['application' => $holder->name]),
        ];
    }

    protected function prepareForValidation(): void
    {
        // No trailing slash: every vhost appends the request path, which has its own.
        if (is_string($this->input('redirect_to'))) {
            $this->merge(['redirect_to' => rtrim(trim($this->input('redirect_to')), '/')]);
        }

        if ($this->has('domain')) {
            $this->merge(['domain' => strtolower(trim((string) $this->input('domain')))]);
        }
    }
}
