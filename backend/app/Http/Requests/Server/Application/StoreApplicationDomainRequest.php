<?php

namespace App\Http\Requests\Server\Application;

use App\Enums\DomainType;
use App\Models\ApplicationDomain;
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
            // Unique across every application, not just this one: two sites
            // claiming one hostname is decided by whichever vhost the web
            // server reads first, which is not a thing to leave to chance.
            //
            // The charset is deliberately strict — this value ends up in a
            // config filename and inside server_name, so anything that could
            // introduce a path separator or a directive break is refused here
            // rather than escaped later.
            'domain' => [
                'required', 'string', 'max:253',
                'regex:/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/i',
                // The last label must hold a letter, as on create. Without it
                // 23.172.120.86 and 127.0.0.1 passed, and the server's IP
                // then served this site instead of a 404 (bug #54).
                'not_regex:/\.[0-9-]+$/',
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
     * The rule is unique across the whole server, so the default message —
     * "The domain has already been taken" — is true and useless: it gives no
     * way to find the site holding the name, and the user's next move is
     * either to detach it there or to pick a different name. Neither is
     * possible without knowing where it is.
     *
     * No leak: `app_domain` is a server-wide permission and applications are
     * not scoped per user, so anyone who can reach this endpoint can already
     * see every application in the list.
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
            'domain.not_regex' => __('errors/application.domain_is_ip'),
            // Falls back to the plain message when the holder cannot be found
            // — a row deleted between the check and this call, say. Better a
            // vaguer sentence than one naming an application that is gone.
            'domain.unique' => $holder === null
                ? __('errors/application.domain_taken')
                : __('errors/application.domain_taken_by', ['application' => $holder->name]),
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('domain')) {
            $this->merge(['domain' => strtolower(trim((string) $this->input('domain')))]);
        }
    }
}
