<?php

namespace App\Http\Requests\Server\Application;

use App\Enums\DomainOrigin;
use App\Rules\AvailablePort;
use App\Rules\StartCommand;
use App\Services\Applications\SiteTypeManager;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Validation is driven by the chosen site type's own field schema, so the form
 * and the rules can never drift apart: WordPress demands an admin email, git
 * demands a repository, and neither can be satisfied by sending the other's
 * fields.
 */
class StoreApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('application') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $manager = app(SiteTypeManager::class);
        $type = $manager->find((string) $this->input('site_type'));

        $rules = [
            'site_type' => ['required', Rule::in($manager->names())],
            // Unique because it names the web-server config file. Two sites
            // sharing a name shared a file, and the second silently replaced
            // the first with nothing anywhere saying so.
            'name' => ['required', 'string', 'max:255', Rule::unique('applications', 'name')],
            'domain' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/'],
            // Whether the hostname above is one the user owns or the temporary
            // `<name>.<ip>.nip.io` the panel offers. The name itself is sent
            // either way — only its provenance differs, and that is what
            // decides whether it may ever go on a certificate.
            //
            // Optional so a caller that predates the create form's toggle keeps
            // working; an unlabelled name is treated as the user's own, and a
            // wildcard-DNS suffix is caught regardless of the label.
            'domain_type' => ['sometimes', new Enum(DomainOrigin::class)],
            'system_user_id' => ['required', 'integer', 'exists:system_users,id'],
            // Both versions become path segments and, for PHP, part of an
            // executed binary path. `max:10` alone let a newline through.
            'php_version' => ['nullable', 'string', 'max:10', 'regex:/^\d+\.\d+$/'],
            'node_version' => ['nullable', 'string', 'max:10', 'regex:/^\d+(\.\d+)*$/'],
            // Any port the owner of this server wants, provided nothing is
            // already using it. The auto-allocated range is a default, not a
            // restriction.
            'app_port' => ['nullable', 'integer', 'between:1024,65535', new AvailablePort],
            // Becomes systemd's ExecStart, which is not a shell — see the rule.
            'start_command' => ['nullable', 'string', 'max:500', new StartCommand],
            // A relative path under the site, and nothing else. This reaches
            // `mkdir -p`, `chown -R` and `tee` as root, so a `..` segment would
            // walk out of the site — `web_root=../../../../etc` handed /etc to
            // the site's own user.
            'web_root' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._\-\/]+$/', 'not_regex:/(^|\/)\.\.(\/|$)/'],
        ];

        // An unknown site_type fails on the rule above; skip type rules so we
        // don't resolve fields on null.
        if ($type === null) {
            return $rules;
        }

        $rules = array_merge($rules, $type->rules());

        // Applied after the merge, and centrally rather than in each type's own
        // `rules()`, so a site type that declares a fixed layout cannot forget
        // to enforce it.
        //
        // Only for the types whose installer *builds* that layout — Craft and
        // Statamic. For everything else the web root is genuinely the user's
        // choice: WordPress unpacks into whatever directory the vhost points
        // at. Here it is not a choice, and accepting one produces a site that
        // 403s on every request while serving the application's own source.
        // `nullable` stays: an omitted value still falls back to the default.
        if (($fixed = $type->fixedWebRoot()) !== null) {
            $rules['web_root'] = ['nullable', Rule::in([$fixed, ltrim($fixed, '/')])];
        }

        return $rules;
    }

    /**
     * `Rule::in` says "the selected web root is invalid", which tells the user
     * nothing about what to select instead. This is the one rule here a caller
     * can hit while doing something entirely reasonable — clearing an advanced
     * field — so it says which value the type needs and why.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $type = app(SiteTypeManager::class)->find((string) $this->input('site_type'));
        $fixed = $type?->fixedWebRoot();

        if ($fixed === null) {
            return [];
        }

        return [
            'web_root.in' => __('validation.web_root_fixed', [
                'type' => __("application.types.{$type->name()}.title"),
                'web_root' => $fixed,
            ]),
        ];
    }

    /**
     * The server has to be able to serve the chosen type — otherwise the
     * record would describe something that can never be provisioned.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $manager = app(SiteTypeManager::class);
                $type = $manager->find((string) $this->input('site_type'));

                if ($type === null) {
                    return;
                }

                // The same check the card grid renders from, so a card the user
                // could click can never be refused here for a reason the grid
                // did not show.
                if (($blocked = $manager->unavailable($type)) !== null) {
                    $validator->errors()->add('site_type', $blocked['reason']);
                }
            },
        ];
    }
}
