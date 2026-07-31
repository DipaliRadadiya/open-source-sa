<?php

namespace App\Services\Applications\Types;

use App\Rules\AvailablePort;
use App\Rules\SafeProviderHost;
use App\Rules\StartCommand;
use Illuminate\Validation\Rule;

/**
 * Deploy the user's own code from a git repository.
 *
 * ONE card, not one per provider — the provider comes from whichever connected
 * account is chosen, so the user never has to know "provider" is a concept.
 *
 * Two ways in:
 *  - a connected account (private or public repos)
 *  - a pasted public URL, which needs no account at all — otherwise a user
 *    with nothing connected hits a dead end on their first visit.
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

    public function servingProfile(): string
    {
        return 'php';
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

            // Asked early, because it decides which of the fields below the
            // form should show at all — and it has no safe default: a Node app
            // served as a directory publishes its source, and a PHP app served
            // by proxy is a permanent 502. So the question is asked outright
            // rather than guessed from the repository.
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
            $this->field('build_command', 'text', advanced: true, extra: [
                'help' => __('application.help.build_command'),
            ]),

            // Only meaningful for server-side rendering — `depends_on` tells
            // the form to hide them otherwise rather than collecting answers
            // that would then be refused.
            $this->field('start_command', 'text', extra: [
                'depends_on' => 'rendering_type',
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
                // A pasted URL is user-controlled and ends up in `git clone`,
                // so it gets the same treatment as a self-hosted GitLab host.
                'string', 'max:255', new SafeProviderHost,
            ],
            'branch' => ['required', 'string', 'max:255'],
            'build_command' => ['nullable', 'string', 'max:500'],

            // `php` covers the common case this card is used for — a Laravel
            // or plain-PHP repository — which is neither built to files nor
            // run as a process.
            'rendering_type' => ['required', Rule::in(['php', 'static', 'csr', 'ssr'])],

            // Required for SSR and refused otherwise. A static site with a
            // start command would get a systemd unit and a proxy vhost for a
            // process nothing routes to; the honest answer is that the two
            // choices are incompatible, not that one silently wins.
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
