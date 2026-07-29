<?php

namespace App\Services\Applications\Types;

use App\Rules\SafeProviderHost;
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
        ], $this->commonFields(), $this->phpFields(), [
            $this->field('build_command', 'text', advanced: true, extra: [
                'help' => __('application.help.build_command'),
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
        ];
    }
}
