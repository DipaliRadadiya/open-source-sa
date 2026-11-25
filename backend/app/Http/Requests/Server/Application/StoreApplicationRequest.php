<?php

namespace App\Http\Requests\Server\Application;

use App\Contracts\SiteType;
use App\Enums\DomainOrigin;
use App\Http\Requests\Server\SystemUser\StoreSystemUserRequest;
use App\Models\Application;
use App\Rules\AvailablePort;
use App\Rules\AvailableSiteName;
use App\Rules\Hostname;
use App\Rules\NotPanelHost;
use App\Rules\SingleLine;
use App\Rules\StartCommand;
use App\Rules\SupportedNodeVersion;
use App\Rules\SupportedPhpVersion;
use App\Services\Applications\ServingProfile;
use App\Services\Applications\SiteTypeManager;
use App\Services\Applications\SiteTypeText;
use App\Services\Server\Applications\ComposeValidator;
use App\Services\Server\Applications\EngineVersionSupport;
use App\Services\Server\Applications\GitDeployer;
use App\Services\Server\Applications\InstallerManager;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Docker\Images\CreateDefaults;
use App\Services\Server\Php\PhpVersionManager;
use App\Services\Server\Php\ServerDefaultPhp;
use App\Services\Server\Runtimes\NodeRuntime;
use App\Services\Server\SystemUsers\ChpasswdLine;
use App\Services\Server\SystemUsers\SystemUsernameGenerator;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;

/**
 * Validation is driven by the chosen site type's own field schema, so the form and the rules
 * cannot drift apart (WordPress demands an admin email, git a repository).
 */
class StoreApplicationRequest extends FormRequest
{
    /**
     * What the image filled in, set by `after()` for a Docker site.
     *
     * @var array{container_port: int|null, volume_mounts: list<array{path: string}>|null, warnings: list<string>, error: string|null, env_error: string|null}|null
     */
    private ?array $dockerDefaults = null;

    /**
     * The validated data plus what the image filled in: the container port when none was typed,
     * its VOLUMEs when no volumes were named.
     *
     * @return array<string, mixed>
     */
    public function creation(): array
    {
        $data = $this->validated();
        $defaults = $this->dockerDefaults;

        if ($defaults === null) {
            return $data;
        }

        if (blank($data['container_port'] ?? null) && $defaults['container_port'] !== null) {
            $data['container_port'] = $defaults['container_port'];
        }

        if ($defaults['volume_mounts'] !== null) {
            $data['volume_mounts'] = $defaults['volume_mounts'];
        }

        return $data;
    }

    /**
     * Things to tell the user about a site that was created anyway.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->dockerDefaults['warnings'] ?? [];
    }

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || ! $user->canManage('application')) {
            return false;
        }

        // Choosing a stored registry credential needs `registry` (view), as on the inspect
        // endpoint (`DockerImageRequest`); otherwise its id is an oracle for someone else's credential.
        return ! $this->filled('registry_id') || $user->canView('registry');
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
            // Unique because it names the web-server config file. `SingleLine`: the name reaches a
            // systemd unit's `Description=`, so a newline would inject a directive.
            // `AvailableSiteName`: unique in the table is not unique on disk (`www` is the distro's
            // PHP pool, `panel` the panel's own vhost).
            'name' => ['required', 'string', 'max:'.Application::MAX_NAME_LENGTH, new SingleLine, Rule::unique('applications', 'name'), new AvailableSiteName],
            'domain' => [
                'required', 'string', 'max:255',
                new Hostname,
                // The domains table owns hostnames server-wide; fail here, not on its unique index
                // after the application row exists.
                Rule::unique('application_domains', 'domain'),
                new NotPanelHost,
            ],
            // Whether the hostname is the user's own or the temporary `<name>.<ip>.nip.io`; that
            // decides whether it may go on a certificate. Optional: an unlabelled name is treated
            // as the user's own, and a wildcard-DNS suffix is caught regardless of the label.
            'domain_type' => ['sometimes', new Enum(DomainOrigin::class)],
            // Let the panel create a dedicated Linux account for this site. Gated on `system_user`
            // manage, not `application` manage: it creates a real account on the box.
            'generate_system_user' => [
                'sometimes', 'boolean',
                function (string $attribute, mixed $value, Closure $fail) {
                    if ($value && ! ($this->user()?->canManage('system_user') ?? false)) {
                        $fail(__('errors/application.generate_system_user_forbidden'));
                    }
                },
            ],

            // The generated account's name and password, in this same request. Only beside
            // `generate_system_user`; same rules as creating a system user on its own.
            'system_user' => ['sometimes', 'array'],
            'system_user.username' => [
                'required_with:system_user', 'string',
                'regex:/^[a-z_][a-z0-9_-]{0,31}$/',
                'unique:system_users,username',
                Rule::notIn(StoreSystemUserRequest::RESERVED),
            ],
            'system_user.password' => ['sometimes', 'nullable', 'string', Password::defaults(), 'not_regex:'.ChpasswdLine::FORBIDDEN],

            // Required only when the caller is choosing one. Sending both a generate flag and an id
            // is refused, not silently resolved, so a site is never owned by an account nobody picked.
            'system_user_id' => [
                Rule::requiredIf(fn () => ! $this->boolean('generate_system_user')),
                'nullable', 'integer', 'exists:system_users,id',
                function (string $attribute, mixed $value, Closure $fail) {
                    if ($value !== null && $this->boolean('generate_system_user')) {
                        $fail(__('errors/application.system_user_conflict'));
                    }
                },
            ],
            // Both versions become path segments and, for PHP, part of an executed binary path
            // (`max:10` alone let a newline through). PHP is also checked against what is installed:
            // on OpenLiteSpeed there is no pool step to fail, so a missing `lsphp` binary would
            // leave an Active site answering 503.
            'php_version' => [
                'nullable', 'string', 'max:10', 'regex:/^\d+\.\d+$/',
                function (string $attribute, mixed $value, Closure $fail) {
                    $installed = app(PhpVersionManager::class)->versions();

                    // Nothing detected may mean an unreadable stack, not "nothing installed"; a
                    // server with no PHP is refused earlier by `SiteTypeManager::unavailable()`.
                    if ($installed === [] || in_array((string) $value, $installed, true)) {
                        return;
                    }

                    $fail(__('php_settings.errors.version_not_installed', ['version' => $value]));
                },
            ],
            // Which database engine to provision, where the application accepts more than one and
            // the server has more than one. Optional: absent means the first accepted engine the
            // server has. Validated against the *installer's* list, as the attach-database endpoint
            // does, so a pairing that provisioning would refuse is never accepted.
            'database_engine' => [
                'nullable', 'string',
                function (string $attribute, mixed $value, Closure $fail) use ($type) {
                    // An unknown site_type is already refused above; there is no installer to ask.
                    if ($type === null) {
                        return;
                    }

                    $installer = app(InstallerManager::class)->installerForType($type->name());

                    if ($installer === null || ! $installer->needsDatabase()) {
                        $fail(__('errors/application.database_engine_not_used'));

                        return;
                    }

                    if (! in_array($value, $installer->acceptedEngines(), true)) {
                        $fail(__('errors/application.database_engine_unsupported', [
                            'application' => app(SiteTypeText::class)->title($type->name()),
                        ]));

                        return;
                    }

                    // Accepted by the application, but not present here ("install it first", not
                    // "this app cannot use that"): different remedies, different screens.
                    if (! app(DatabaseManager::class)->engine($value)->available()) {
                        $fail(__('errors/application.database_engine_unavailable'));

                        return;
                    }

                    // Present, but older than the application runs on ("upgrade it"); otherwise the
                    // site is created and dies inside the application's own installer.
                    $minimum = app(EngineVersionSupport::class)
                        ->shortfall($value, $installer->minimumEngineVersions());

                    if ($minimum !== null) {
                        $fail(__('errors/application.database_engine_too_old', [
                            'application' => app(SiteTypeText::class)->title($type->name()),
                            'engine' => (string) config("server.databases.engines.{$value}.label", $value),
                            'minimum' => $minimum,
                        ]));
                    }
                },
            ],
            // Checked against what is on the box too: the version becomes a path in the systemd
            // unit's `ExecStart`, which systemd does not stat, so a missing one gives an Active
            // site that 502s on every start.
            'node_version' => [
                'nullable', 'string', 'max:10', 'regex:/^\d+(\.\d+)*$/',
                function (string $attribute, mixed $value, Closure $fail) {
                    $node = app(NodeRuntime::class);

                    // Empty means fnm is missing or unreadable, not "no versions" (see `php_version`).
                    if ($node->versions() === [] || $node->installed((string) $value)) {
                        return;
                    }

                    $fail(__('errors/node.not_installed', ['version' => $value]));
                },
            ],
            // Any port nothing is using; the auto-allocated range is a default, not a restriction.
            'app_port' => ['nullable', 'integer', 'between:1024,65535', new AvailablePort],
            // Becomes systemd's ExecStart, which is not a shell — see the rule.
            'start_command' => ['nullable', 'string', 'max:500', new StartCommand],
            // A relative path under the site only. It reaches `mkdir -p`, `chown -R` and `tee` as
            // root, so a `..` segment would walk out of the site (`../../../../etc`).
            'web_root' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._\-\/]+$/', 'not_regex:/(^|\/)\.\.(\/|$)/'],
        ];

        // An unknown site_type fails on the rule above; skip type rules.
        if ($type === null) {
            return $rules;
        }

        $rules = array_merge($rules, $type->rules());

        // Applied after the merge, centrally, so a type with a fixed layout cannot forget to
        // enforce it. Only for types whose installer *builds* that layout (Craft, Statamic):
        // another web root would 403 every request while serving the application's source.
        // `nullable` stays: an omitted value still falls back to the default.
        if (($fixed = $type->fixedWebRoot()) !== null) {
            $rules['web_root'] = ['nullable', Rule::in([$fixed, ltrim($fixed, '/')])];
        }

        // The type knows which Node versions its application runs on. Appended, so the shape checks
        // still run first; this rule answers "will it run", not "is this a version string".
        if (($range = $type->supportedNodeRange()) !== null) {
            $rules['node_version'][] = new SupportedNodeVersion(
                $range['min'] ?? null,
                $range['max'] ?? null,
                app(SiteTypeText::class)->title($type->name()),
            );
        }

        // The same for PHP, appended for the same reason (shape and is-installed checks run first).
        if (($rule = $this->phpRangeRule($type)) !== null) {
            $rules['php_version'][] = $rule;
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
     * The type's supported PHP range, as a rule carrying the version an empty field resolves to.
     *
     * Built here because `after()` needs the same object: a `ValidationRule` is not implicit, so
     * Laravel skips it when the attribute is empty.
     */
    private function phpRangeRule(?SiteType $type): ?SupportedPhpVersion
    {
        if ($type === null || ($range = $type->supportedPhpRange()) === null) {
            return null;
        }

        return new SupportedPhpVersion(
            $range['min'] ?? null,
            $range['max'] ?? null,
            app(SiteTypeText::class)->title($type->name()),
            // What an empty field will actually run on (provisioning resolves a blank one to the
            // server default), so the range also applies to an untouched field.
            app(ServerDefaultPhp::class)->version(),
        );
    }

    /**
     * `Rule::in` says only "the selected web root is invalid"; this says which value the type
     * needs and why.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $type = app(SiteTypeManager::class)->find((string) $this->input('site_type'));
        $fixed = $type?->fixedWebRoot();

        // Name the password rule instead of "format is invalid". n8n's policy has no symbol rule;
        // Moodle's does.
        $messages = ['admin_password.regex' => __($type?->name() === 'n8n'
            ? 'validation.admin_password_strength_no_symbol'
            : 'validation.admin_password_strength')];

        // Same sentence as the other Docker name fields (ExistingDockerVolume, NewDockerName).
        $messages['volume_mounts.*.volume.regex'] = __('validation.docker_volume_invalid');

        if ($fixed === null) {
            return $messages;
        }

        return $messages + [
            'web_root.in' => __('validation.web_root_fixed', [
                'type' => app(SiteTypeText::class)->title($type->name()),
                'web_root' => $fixed,
            ]),
        ];
    }

    /**
     * The server has to be able to serve the chosen type.
     */
    public function after(): array
    {
        return [
            // A chosen name only for a generated account, and one the server does not already have
            // (a hand-made account is in /etc/passwd with no row here).
            function (Validator $validator) {
                if (! $this->has('system_user') || $validator->errors()->isNotEmpty()) {
                    return;
                }

                if (! $this->boolean('generate_system_user')) {
                    $validator->errors()->add('system_user', __('errors/application.system_user_needs_generate'));

                    return;
                }

                $username = (string) $this->input('system_user.username');

                if (! app(SystemUsernameGenerator::class)->available($username)) {
                    $validator->errors()->add('system_user.username', __('errors/system-user.username_taken_on_server'));
                }
            },
            function (Validator $validator) {
                $manager = app(SiteTypeManager::class);
                $type = $manager->find((string) $this->input('site_type'));

                if ($type === null) {
                    return;
                }

                // The same check the card grid renders from.
                if (($blocked = $manager->unavailable($type)) !== null) {
                    $validator->errors()->add('site_type', $blocked['reason']);

                    return;
                }

                // A type served more than one way (git) is offered when the server hosts any of
                // them; the way this site would be served must be one it hosts.
                if (count($type->servingProfiles()) > 1
                    && ($refusal = $manager->unservedProfile(ServingProfile::resolve($type, $this->all()))) !== null) {
                    $validator->errors()->add('rendering_type', $refusal);
                }
            },

            // A pasted compose file, checked here rather than at deploy time, so a refusal arrives
            // on the field before any row or vhost exists. Still checked in the supervisor too:
            // the form is not the only way a row changes.
            function (Validator $validator) {
                $compose = (string) $this->input('compose');

                if (trim($compose) === '' || $validator->errors()->isNotEmpty()) {
                    return;
                }

                // Validated against a directory that exists: Compose resolves relative bind sources
                // against the working directory, and the site's own one is not created yet.
                $composeValidator = app(ComposeValidator::class);
                $verdict = $composeValidator->validate($compose, sys_get_temp_dir());

                foreach ($verdict['errors'] as $error) {
                    $validator->errors()->add('compose', $error);
                }

                // A port another site holds; otherwise it fails later on the unique index.
                $taken = $verdict['resolved'] === null ? null : $composeValidator->portTaken(
                    $verdict['resolved'],
                    filled($this->input('container_port')) ? (int) $this->input('container_port') : null,
                );

                if ($taken !== null) {
                    $validator->errors()->add('compose', $taken);
                }

                // Docker's own networks and every site's private one: the site does not exist yet,
                // so none of them is its own.
                $network = $verdict['resolved'] === null ? null : $composeValidator->unjoinableNetwork($verdict['resolved']);

                if ($network !== null) {
                    $validator->errors()->add('compose', $network);
                }
            },

            // A Docker image site: what the image says about itself. Last of the cheap checks and
            // only when everything else passed, because it may ask a registry. Port, default
            // volumes and warnings are kept for the controller; see creation() and warnings().
            function (Validator $validator) {
                if ((string) $this->input('site_type') !== 'docker') {
                    return;
                }

                if (filled($this->input('compose'))) {
                    // Env and volumes go into the GENERATED file; a pasted one says its own, so
                    // these are refused rather than silently dropped.
                    foreach (['env', 'volume_mounts'] as $field) {
                        if (filled($this->input($field))) {
                            $validator->errors()->add($field, __('validation.prohibited', ['attribute' => $field]));
                        }
                    }

                    return;
                }

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $seen = [];
                foreach ((array) $this->input('env', []) as $index => $row) {
                    $key = (string) ($row['key'] ?? '');

                    if (isset($seen[$key])) {
                        $validator->errors()->add("env.{$index}.key", __('application.docker_create.env_key_duplicate', ['key' => $key]));
                    }

                    $seen[$key] = true;
                }

                $paths = [];
                foreach ((array) $this->input('volume_mounts', []) as $index => $mount) {
                    $path = rtrim((string) ($mount['path'] ?? ''), '/');

                    if (isset($paths[$path])) {
                        $validator->errors()->add("volume_mounts.{$index}.path", __('validation.docker_mount_duplicate', ['path' => $path]));
                    }

                    $paths[$path] = true;
                }

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->dockerDefaults = app(CreateDefaults::class)->resolve($this->all());

                if ($this->dockerDefaults['error'] !== null) {
                    $validator->errors()->add('container_port', $this->dockerDefaults['error']);
                }

                if ($this->dockerDefaults['env_error'] !== null) {
                    $validator->errors()->add('env', $this->dockerDefaults['env_error']);
                }
            },

            // A git site's repository and branch, before anything exists. Last of the field checks
            // and only when everything else passed, because it reaches the git host.
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()
                    || app(SiteTypeManager::class)->find((string) $this->input('site_type'))?->method() !== 'git') {
                    return;
                }

                $probe = (new Application)->forceFill([
                    'git_account_id' => $this->input('git_account_id'),
                    'repository' => $this->input('repository'),
                    'repository_url' => $this->input('repository_url'),
                    'branch' => $this->input('branch'),
                ]);

                $reason = app(GitDeployer::class)->checkRemoteBranch($probe);

                if ($reason !== null) {
                    $field = match (true) {
                        $reason === 'branch_missing' => 'branch',
                        $this->filled('repository_url') => 'repository_url',
                        default => 'repository',
                    };

                    $validator->errors()->add($field, __("validation.git_{$reason}", ['branch' => (string) $this->input('branch')]));
                }
            },

            // The PHP range for the value `rules()` cannot reach: a custom rule is skipped when the
            // attribute is empty, and an empty `php_version` means the server default (the newest
            // PHP, which a ceiling exists to exclude).
            function (Validator $validator) {
                if (filled($this->input('php_version'))) {
                    return;
                }

                $type = app(SiteTypeManager::class)->find((string) $this->input('site_type'));

                $this->phpRangeRule($type)?->validate(
                    'php_version',
                    null,
                    fn (string $message) => $validator->errors()->add('php_version', $message),
                );
            },
        ];
    }
}
