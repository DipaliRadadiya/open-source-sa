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
 * Exists because deleting a git account is allowed to succeed. The foreign key
 * is `nullOnDelete`, so disconnecting an account leaves its applications with
 * no credential — and previously with no way back, since the account could
 * only ever be chosen while creating the site. Recovery meant deleting the
 * application and building it again.
 *
 * **Verified before it is persisted.** The candidate account is asked to list
 * the repository's branches, and only a successful answer is written. Storing
 * the link first would move the failure to the next deploy, where it reads as
 * a deployment problem rather than as "that credential cannot see that
 * repository" — and would leave the application pointing at something worse
 * than what it had. `UpdateGitAccount` takes the same care with a rotated
 * token, verifying a replica before touching the stored record.
 *
 * Nothing on disk has to change. The token never reaches `.git/config` — it
 * travels in an ephemeral credential file that is removed after every deploy —
 * and `GitDeployer` rewrites `origin` on each run, so a changed host or
 * repository path corrects itself on the next deployment rather than needing a
 * migration of the checkout.
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

        // Listing branches is the cheapest question that proves the whole
        // thing: the token is valid, it has not been revoked, and its scopes
        // reach *this* repository. A token that verifies against the provider
        // but cannot see this repository is exactly the case a plain
        // credential check would pass and the next deploy would fail on.
        try {
            $branches = $this->providers->driver($account->provider)->branches($account, $repository);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'repository' => __('git.relink.repository_unreachable'),
            ]);
        }

        // The drivers return `[['name' => ..., 'protected' => ...], ...]`, not
        // a list of strings — comparing against the rows themselves silently
        // never matches, and would have rejected every branch that exists.
        $names = array_column($branches, 'name');

        if ($branch !== '' && $names !== [] && ! in_array($branch, $names, true)) {
            // Caught here rather than at the next deploy, where a missing
            // branch surfaces as a failed fetch against a reference nobody
            // asked about.
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
     * A webhook's provider is defaulted from the account it was configured
     * with, so moving a site from GitHub to GitLab leaves it verifying
     * signatures against the wrong scheme — it would reject every delivery,
     * silently, as though the remote had stopped sending them.
     *
     * Disabled rather than rewritten: the new provider needs its own webhook
     * created at its own remote, which is a deliberate act, not something to
     * infer from a credential change.
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

        // `webhook_identifier` is kept: it is the public part of the delivery
        // URL and is unique, so discarding it would hand the next webhook a
        // different address for the same site with nothing gained.
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
