<?php

namespace App\Services\Applications\Types;

use App\Rules\AvailablePort;
use App\Rules\DeployScriptPhpInstalled;
use App\Rules\SafeProviderHost;
use App\Rules\StartCommand;
use Illuminate\Validation\Rule;

/**
 * Deploy the user's own code from a git repository.
 *
 * One card for every provider: the provider comes from the chosen connected account.
 *
 * Two ways in:
 *  - a connected account (private or public repos)
 *  - a pasted public URL, which needs no account at all
 */
class GitSiteType extends AbstractSiteType
{
    public function name(): string
    {
        return 'git';
    }

    public function method(): string
    {
        return 'git';
    }

    /**
     * The fallback only. The real profile comes from the rendering type the user picks (see
     * `ServingProfile::resolve()`); this is used when a payload has neither a rendering type
     * nor a start command.
     */
    public function servingProfile(): string
    {
        return 'php';
    }

    /**
     * All three, because the rendering type on this card chooses between them. Gating on
     * `servingProfile()` alone would hide "deploy from git" from node/static-only stacks such as
     * MERN. The order matches `ServingProfile::resolve()`'s branches.
     *
     * @return array<int, string>
     */
    public function servingProfiles(): array
    {
        return ['php', 'node', 'static'];
    }

    public function category(): string
    {
        return 'developer';
    }

    public function icon(): string
    {
        return 'git-branch';
    }

    public function popular(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return array_merge([
            $this->field('git_source', 'select', required: true, extra: [
                'default' => 'account',
                'options' => [
                    ['value' => 'account', 'label' => __('application.git_source.account')],
                    ['value' => 'public_url', 'label' => __('application.git_source.public_url')],
                ],
            ]),

            // Path A — a connected account. Each dropdown waits for its parent.
            $this->field('git_account_id', 'select', extra: [
                'source' => 'git_accounts',
                'depends_on' => 'git_source',
            ]),
            $this->field('repository', 'select', extra: [
                'source' => 'git_repositories',
                'depends_on' => 'git_account_id',
            ]),

            // Path B — a public repository, no credentials involved.
            $this->field('repository_url', 'url', extra: [
                'depends_on' => 'git_source',
                'help' => __('application.help.repository_url'),
            ]),

            $this->field('branch', 'select', required: true, extra: [
                'source' => 'git_branches',
                'depends_on' => 'repository',
            ]),

            // Asked early, as it decides which fields below show. No safe default: a Node app
            // served as a directory publishes its source, a PHP app served by proxy is a 502.
            $this->field('rendering_type', 'select', required: true, extra: [
                'options' => [
                    ['value' => 'php', 'label' => __('application.rendering.php')],
                    ['value' => 'ssr', 'label' => __('application.rendering.ssr')],
                    ['value' => 'csr', 'label' => __('application.rendering.csr')],
                    ['value' => 'static', 'label' => __('application.rendering.static')],
                ],
                'help' => __('application.help.rendering_type'),
            ]),
        ], $this->commonFields(), $this->phpFields(), [
            // Not `nodeFields()`: that also carries `app_port`, declared below with its own
            // `rendering_type` depends_on. `node_version` is optional (unset runs on whatever
            // `node` is on PATH) and only for ssr/csr, as a PHP or static repo has no Node to pin.
            $this->field('node_version', 'select', extra: [
                'depends_on' => 'node_rendering',
                'source' => 'node_versions',
            ]),
            // Only a Node app picks a package manager. `node_rendering` is a second `depends_on`
            // sentinel: the field belongs to both ssr and csr, not just the ssr-only fields below.
            $this->field('package_manager', 'select', extra: [
                'depends_on' => 'node_rendering',
                'required_if' => ['rendering_type' => ['ssr', 'csr']],
                'default' => 'npm',
                'options' => [
                    ['value' => 'npm', 'label' => __('application.package_manager.npm')],
                    ['value' => 'yarn', 'label' => __('application.package_manager.yarn')],
                    ['value' => 'pnpm', 'label' => __('application.package_manager.pnpm')],
                    ['value' => 'bun', 'label' => __('application.package_manager.bun')],
                ],
                // Sent to the frontend so the install+build commands have one source of truth.
                'build_templates' => (array) config('server.deployments.package_manager_scripts', []),
                'help' => __('application.help.package_manager'),
            ]),
            $this->field('build_command', 'text', advanced: true, extra: [
                'help' => __('application.help.build_command'),
            ]),

            // Offered at creation: the first deploy runs automatically after provisioning and
            // decides whether the site works at all (e.g. a Laravel repo's migrations).
            $this->field('deploy_script', 'textarea', advanced: true, extra: [
                'help' => __('application.help.deploy_script'),
            ]),

            // Only meaningful for server-side rendering: `depends_on` hides them otherwise.
            $this->field('start_command', 'text', extra: [
                'depends_on' => 'rendering_type',
                // Required for SSR, as the rules below say.
                'required_if' => ['rendering_type' => ['ssr']],
                'help' => __('application.help.start_command'),
            ]),
            $this->field('app_port', 'number', extra: [
                'depends_on' => 'rendering_type',
                'help' => __('application.help.app_port'),
            ]),
        ]);
    }

    public function rules(): array
    {
        return [
            'git_source' => ['required', Rule::in(['account', 'public_url'])],

            // Exactly one path: an account with a repository, or a public URL.
            'git_account_id' => [
                Rule::requiredIf(fn () => request()->input('git_source') === 'account'),
                Rule::excludeIf(request()->input('git_source') !== 'account'),
                'integer', 'exists:git_accounts,id',
            ],
            'repository' => [
                Rule::requiredIf(fn () => request()->input('git_source') === 'account'),
                Rule::excludeIf(request()->input('git_source') !== 'account'),
                'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+(\/[A-Za-z0-9._-]+)+$/',
                'not_regex:/(^|\/)\.{1,2}(\/|$)/',
            ],
            'repository_url' => [
                Rule::requiredIf(fn () => request()->input('git_source') === 'public_url'),
                Rule::excludeIf(request()->input('git_source') !== 'public_url'),
                // User-controlled and passed to `git clone`: same check as a self-hosted GitLab host.
                'string', 'max:255', new SafeProviderHost,
            ],
            'branch' => ['required', 'string', 'max:255'],
            'build_command' => ['nullable', 'string', 'max:500'],

            // Same cap and no content validation, as on the Deployment screen: it is a script the
            // user runs as their own site user. The control is the privilege drop, not a denylist.
            'deploy_script' => ['nullable', 'string', 'max:65535', new DeployScriptPhpInstalled],

            // Required for a Node app, refused otherwise.
            'package_manager' => [
                Rule::requiredIf(fn () => in_array(request()->input('rendering_type'), ['ssr', 'csr'], true)),
                Rule::excludeIf(fn () => ! in_array(request()->input('rendering_type'), ['ssr', 'csr'], true)),
                Rule::in(['npm', 'yarn', 'pnpm', 'bun']),
            ],

            // `php`: a Laravel or plain-PHP repository, neither built to files nor run as a process.
            'rendering_type' => ['required', Rule::in(['php', 'static', 'csr', 'ssr'])],

            // Required for SSR, refused otherwise: a static site with a start command would get a
            // systemd unit and proxy vhost for a process nothing routes to.
            'start_command' => [
                Rule::requiredIf(fn () => request()->input('rendering_type') === 'ssr'),
                Rule::excludeIf(request()->input('rendering_type') !== 'ssr'),
                'string', 'max:500', new StartCommand,
            ],
            'app_port' => [
                Rule::excludeIf(request()->input('rendering_type') !== 'ssr'),
                'nullable', 'integer', 'between:1024,65535', new AvailablePort,
            ],
        ];
    }
}
