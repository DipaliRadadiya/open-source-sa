<?php

namespace App\Http\Requests\Server\Application;

use App\Models\Application;
use App\Rules\SafeProviderHost;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Re-point a git application at a different account, repository or public URL.
 *
 * The account was create-time only, which made a revoked token or a
 * disconnected account permanent: the site kept its repository and branch and
 * had no way back to a credential that could read them. Deleting and
 * recreating the site was the only route, and that is not a recovery, it is a
 * rebuild.
 *
 * The same two mutually exclusive paths as creation — an account plus an
 * `owner/repo`, or a public URL — because they are the same decision made
 * later, and a second set of rules for it would be a second place to get the
 * traversal and host checks wrong.
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
     * The common case by a distance is a site that is deployed and correct and
     * has simply lost its credential: the repository, the branch and the URL
     * are all still right, and the only thing missing is an account that can
     * read them. Requiring `git_source` and `repository` to be restated for
     * that made the user retype an `owner/repo` they never changed — friction,
     * and a chance to turn a working site into a broken one with a typo.
     *
     * So the payload can be `{"git_account_id": 5}` and nothing else. The
     * fuller form still works unchanged; these are defaults, not overrides —
     * anything the request states wins.
     */
    protected function prepareForValidation(): void
    {
        $application = $this->route('application');

        if (! $application instanceof Application) {
            return;
        }

        $this->merge([
            // Named by what was sent, not by what the site is today: sending an
            // account is choosing the account path even on a site that is
            // currently a public URL, and vice versa. Only when neither is
            // named does the site's own mode decide.
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
                // Ends up in a git remote, so it gets the same treatment as a
                // self-hosted GitLab host on the create form.
                'string', 'max:255', new SafeProviderHost,
            ],

            // Optional here, unlike on create. Re-pointing at the same
            // repository under a new credential should not make the user
            // restate a branch that has not changed; sending one switches
            // branch in the same call, which is what a move to a different
            // repository usually needs.
            'branch' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
