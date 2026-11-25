<?php

namespace App\Actions\Server\Application;

use App\Models\Application;
use App\Models\GitAccount;
use App\Services\ActivityLogger;
use App\Services\Git\GitProviderManager;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Point a git application at a different account, repository or public URL.
 *
 * Deleting a git account succeeds (`nullOnDelete`) and leaves its applications with no
 * credential; this is the way back, since the account was otherwise only chosen at creation.
 *
 * **Verified before it is persisted.** The candidate account must list the repository's branches
 * first; storing the link first would surface the failure at the next deploy, as a deployment
 * problem, with the application pointing somewhere worse. `UpdateGitAccount` does the same for a
 * rotated token.
 *
 * Nothing on disk changes: the token never reaches `.git/config` (an ephemeral credential file
 * is removed after each deploy) and `GitDeployer` rewrites `origin` on every run.
 */
class RelinkGitAccount
{
    public function __construct(
        private GitProviderManager $providers,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Application $application, array $data): Application
    {
        $branch = (string) ($data['branch'] ?? $application->branch);

        $attributes = $data['git_source'] === 'account'
            ? $this->accountAttributes($data, $branch)
            : [
                'git_account_id' => null,
                'repository' => null,
                'repository_url' => $data['repository_url'],
                'branch' => $branch,
            ];

        $previousProvider = $application->gitAccount?->provider;

        $application->forceFill($attributes)->save();

        $this->clearWebhookOnProviderChange($application, $previousProvider);

        $this->activityLogger->log('application.git_account_relinked', $application, [
            'name' => $application->name,
            'repository' => $attributes['repository'] ?? $attributes['repository_url'],
        ]);

        return $application->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function accountAttributes(array $data, string $branch): array
    {
        $account = GitAccount::findOrFail($data['git_account_id']);
        $repository = (string) $data['repository'];

        // Listing branches proves the token is valid, not revoked, and can see *this* repository,
        // which a plain credential check does not.
        try {
            $branches = $this->providers->driver($account->provider)->branches($account, $repository);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'repository' => __('git.relink.repository_unreachable'),
            ]);
        }

        // Drivers return `[['name' => ..., 'protected' => ...], ...]`, not strings.
        $names = array_column($branches, 'name');

        if ($branch !== '' && $names !== [] && ! in_array($branch, $names, true)) {
            // Caught here, not as a failed fetch at the next deploy.
            throw ValidationException::withMessages([
                'branch' => __('git.relink.branch_missing', ['branch' => $branch]),
            ]);
        }

        return [
            'git_account_id' => $account->id,
            'repository' => $repository,
            'repository_url' => null,
            'branch' => $branch,
        ];
    }

    /**
     * A webhook's provider comes from the account it was set up with, so moving a site from GitHub
     * to GitLab would verify signatures with the wrong scheme and silently reject every delivery.
     *
     * Disabled, not rewritten: the new provider needs a webhook created at its own remote.
     */
    private function clearWebhookOnProviderChange(Application $application, ?string $previousProvider): void
    {
        $provider = $application->fresh()->gitAccount?->provider;

        if ($previousProvider === null || $provider === $previousProvider) {
            return;
        }

        if (! $application->webhook_enabled) {
            return;
        }

        // `webhook_identifier` is kept: it is the public, unique part of the delivery URL.
        $application->forceFill([
            'webhook_enabled' => false,
            'webhook_provider' => null,
            'webhook_secret' => null,
        ])->save();

        $this->activityLogger->log('application.webhook_disabled', $application, [
            'name' => $application->name,
        ]);
    }
}
