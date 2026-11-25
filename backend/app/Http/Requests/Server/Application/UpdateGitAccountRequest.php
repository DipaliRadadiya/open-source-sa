<?php

namespace App\Http\Requests\Server\Application;

use App\Models\Application;
use App\Rules\SafeProviderHost;
use App\Services\Server\Applications\GitDeployer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Re-point a git application at a different account, repository or public URL.
 *
 * Lets a site recover from a revoked token or disconnected account without being rebuilt.
 *
 * The same two mutually exclusive paths as creation (an account plus an `owner/repo`, or a public
 * URL): a second set of rules would be a second place to get the traversal and host checks wrong.
 */
class UpdateGitAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canManage('app_deployment');
    }

    /**
     * Fill in what the application already knows.
     *
     * The common case is a correct site that lost its credential, so the payload can be just
     * `{"git_account_id": 5}`. These are defaults, not overrides: anything the request states wins.
     */
    protected function prepareForValidation(): void
    {
        $application = $this->route('application');

        if (! $application instanceof Application) {
            return;
        }

        $this->merge([
            // Decided by what was sent, not by what the site is today. Only when neither is named
            // does the site's own mode decide.
            'git_source' => $this->input('git_source') ?? match (true) {
                $this->filled('git_account_id') => 'account',
                $this->filled('repository_url') => 'public_url',
                $application->git_account_id !== null => 'account',
                default => 'public_url',
            },

            'repository' => $this->input('repository') ?? $application->repository,
            'repository_url' => $this->input('repository_url') ?? $application->repository_url,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'git_source' => ['required', Rule::in(['account', 'public_url'])],

            'git_account_id' => [
                Rule::requiredIf(fn () => $this->input('git_source') === 'account'),
                Rule::excludeIf($this->input('git_source') !== 'account'),
                'integer', 'exists:git_accounts,id',
            ],

            'repository' => [
                Rule::requiredIf(fn () => $this->input('git_source') === 'account'),
                Rule::excludeIf($this->input('git_source') !== 'account'),
                'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+(\/[A-Za-z0-9._-]+)+$/',
                'not_regex:/(^|\/)\.{1,2}(\/|$)/',
            ],

            'repository_url' => [
                Rule::requiredIf(fn () => $this->input('git_source') === 'public_url'),
                Rule::excludeIf($this->input('git_source') !== 'public_url'),
                // Ends up in a git remote, so it gets the same check as a self-hosted GitLab host.
                'string', 'max:255', new SafeProviderHost,
            ],

            // Optional here, unlike on create: re-pointing under a new credential should not need
            // the branch restated; sending one switches branch in the same call.
            'branch' => ['sometimes', 'string', 'max:255'],
        ];
    }

    /**
     * A public URL must answer, and have the branch, before it is saved. The account path is
     * checked in `RelinkGitAccount` against the provider's API; this one has no API to ask, so it
     * gets the same `git ls-remote` probe as creating a site.
     *
     * Runs last, and only when the fields passed, because it reaches the git host.
     *
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $application = $this->route('application');

            if (! $application instanceof Application || $validator->errors()->isNotEmpty()
                || $this->input('git_source') !== 'public_url') {
                return;
            }

            $probe = (new Application)->forceFill([
                'git_account_id' => null,
                'repository_url' => (string) $this->input('repository_url'),
                'branch' => (string) ($this->input('branch') ?? $application->branch),
            ]);

            if (($reason = app(GitDeployer::class)->checkRemoteBranch($probe)) !== null) {
                $validator->errors()->add(
                    $reason === 'branch_missing' ? 'branch' : 'repository_url',
                    __("validation.git_{$reason}", ['branch' => $probe->branch]),
                );
            }
        }];
    }
}
