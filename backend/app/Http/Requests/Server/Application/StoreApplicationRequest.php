<?php

namespace App\Http\Requests\Server\Application;

use App\Enums\DomainOrigin;
use App\Rules\AvailablePort;
use App\Rules\SingleLine;
use App\Rules\StartCommand;
use App\Rules\SupportedNodeVersion;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\Php\PhpVersionManager;
use App\Services\Server\Runtimes\NodeRuntime;
use Closure;
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
            // `SingleLine` because the name reaches the systemd unit's
            // `Description=` for a Node application: a newline there injects a
            // directive into a file the panel writes and systemd runs.
            'name' => ['required', 'string', 'max:255', new SingleLine, Rule::unique('applications', 'name')],
            'domain' => [
                'required', 'string', 'max:255',
                'regex:/^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/',
                // The domains table owns hostnames across the whole server.
                // Validate that fact here rather than letting its unique
                // index surface as a database exception after the application
                // row has already been inserted.
                Rule::unique('application_domains', 'domain'),
            ],
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
            //
            // Checked against what is installed as well as its shape, the same
            // way `SavePhpSettingsRequest` already checks it on the version
            // *change* endpoint. Creation did not, and the two web-server
            // families fail differently: on nginx and Apache the pool step
            // writes into an `/etc/php/<version>` that does not exist and
            // provisioning stops with something to read. On OpenLiteSpeed
            // there is no pool step at all — `PoolManager::supported()` is
            // true only for the FPM stack — so nothing checked, the vhost was
            // written naming an `lsphp` binary that is not on the box, the
            // config test passed (it does not stat the interpreter), and the
            // site went Active and answered 503 on every request.
            'php_version' => [
                'nullable', 'string', 'max:10', 'regex:/^\d+\.\d+$/',
                function (string $attribute, mixed $value, Closure $fail) {
                    $installed = app(PhpVersionManager::class)->versions();

                    // Nothing detected is not "nothing installed" — it is also
                    // what a stack we cannot read looks like. Refusing every
                    // creation on the strength of an empty list would turn an
                    // unreadable directory into a server that cannot host a
                    // site. A server with genuinely no PHP is already refused
                    // one layer up, by the runtime capability check in
                    // `SiteTypeManager::unavailable()`.
                    if ($installed === [] || in_array((string) $value, $installed, true)) {
                        return;
                    }

                    $fail(__('php_settings.errors.version_not_installed', ['version' => $value]));
                },
            ],
            // The same hole as `php_version`, one runtime along. A Node
            // version was checked for shape, and — once `SupportedNodeVersion`
            // existed — against the range the application runs on, but never
            // against what is on the box. Nothing else checks it either: the
            // version becomes a path in `NodeRuntime::binaryPath()` that goes
            // straight into the systemd unit's `ExecStart`, and systemd does
            // not stat a binary when a unit is written. The site is created,
            // reported Active, and the unit fails on every start with a 502
            // from the vhost in front of a port nobody is listening on.
            'node_version' => [
                'nullable', 'string', 'max:10', 'regex:/^\d+(\.\d+)*$/',
                function (string $attribute, mixed $value, Closure $fail) {
                    $node = app(NodeRuntime::class);

                    // Empty means fnm is not installed or could not be read,
                    // which is not the same as "no versions" — see the note on
                    // `php_version` above. A site type that needs Node is
                    // already refused on a server without it by the runtime
                    // capability check.
                    if ($node->versions() === [] || $node->installed((string) $value)) {
                        return;
                    }

                    $fail(__('errors/node.not_installed', ['version' => $value]));
                },
            ],
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

        // Same reasoning, same place: the type knows which Node versions its
        // application runs on, and until this existed nothing acted on that.
        // Appended rather than replacing the base rules, so the shape checks
        // above still run first — this rule answers "will it run", not "is
        // this a version string".
        if (($range = $type->supportedNodeRange()) !== null) {
            $rules['node_version'][] = new SupportedNodeVersion(
                $range['min'] ?? null,
                $range['max'] ?? null,
                __("application.types.{$type->name()}.title"),
            );
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('domain')) {
            $this->merge(['domain' => strtolower(trim((string) $this->input('domain')))]);
        }
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
