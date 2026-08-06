<?php

namespace App\Services\Server\Applications;

use App\Enums\AiBotPolicy;
use App\Exceptions\Server\Application\BotBlockerOperationException;
use App\Models\Application;
use App\Models\ApplicationBotRule;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Support\Collection;

/**
 * The AI Bot Blocker: one policy column plus a per-site list of additions and
 * exemptions. Changing any of them only ever means re-rendering the vhost
 * with a different (or no) user-agent block.
 *
 * Same apply → test → reload → rollback shape as `BasicAuthManager`,
 * `Waf8GManager` and `ApplicationProvisioner::disable()`/`enable()`, and the
 * rules borrow the WAF's discipline exactly: they are rendered from an
 * in-memory collection and only written to the database once the config test
 * has proved the new state is safe. A failed test must leave the database as
 * it was.
 */
class BotBlockerManager
{
    public function __construct(
        private WebServerManager $webServers,
        private ApplicationProvisioner $provisioner,
    ) {}

    /**
     * @param  array<int, string>|null  $blocked  null leaves the stored list alone
     * @param  array<int, string>|null  $allowed  null leaves the stored list alone
     */
    public function apply(
        Application $application,
        AiBotPolicy $policy,
        ?array $blocked = null,
        ?array $allowed = null,
    ): void {
        $previous = $application->ai_bot_policy;
        $rulesChange = $blocked !== null || $allowed !== null;

        $pending = $this->pendingRules($application, $blocked, $allowed);

        if ($previous === $policy && ! $this->rulesDiffer($application, $pending)) {
            return;
        }

        $application->ai_bot_policy = $policy;
        $application->setRelation('botRules', $pending);

        $applied = $this->applyVhost($application);

        if ($applied->failed()) {
            $this->restore($application, $previous, $rulesChange);

            throw new BotBlockerOperationException($applied->reference);
        }

        if ($this->webServers->driver()->test()->failed()) {
            $restored = $this->restore($application, $previous, $rulesChange);

            throw new BotBlockerOperationException($restored->reference);
        }

        $this->webServers->driver()->reload();

        $application->save();

        if (! $rulesChange) {
            return;
        }

        // Replaced wholesale, and only now — see the class docblock.
        $application->botRules()->delete();

        foreach ($pending as $rule) {
            $application->botRules()->create(['type' => $rule->type, 'value' => $rule->value]);
        }
    }

    /**
     * Put the previous policy and the stored rules back into the live config.
     * Nothing has been reloaded at either call site, so this is restoring the
     * files to match what the server is still serving.
     */
    private function restore(Application $application, ?AiBotPolicy $previous, bool $rulesChange): ServerOpsResult
    {
        $application->ai_bot_policy = $previous;

        if ($rulesChange) {
            $application->load('botRules');
        }

        return $this->applyVhost($application);
    }

    /**
     * The rule set the new config should be rendered against: the incoming
     * lists where given, the stored ones where not.
     *
     * @param  array<int, string>|null  $blocked
     * @param  array<int, string>|null  $allowed
     * @return Collection<int, ApplicationBotRule>
     */
    private function pendingRules(Application $application, ?array $blocked, ?array $allowed): Collection
    {
        $application->loadMissing('botRules');

        $stored = fn (string $type) => $application->botRules
            ->where('type', $type)
            ->pluck('value')
            ->all();

        $build = fn (string $type, array $values) => collect($values)->map(
            fn (string $value) => new ApplicationBotRule(['type' => $type, 'value' => $value]),
        );

        return $build('block', $blocked ?? $stored('block'))
            ->concat($build('allow', $allowed ?? $stored('allow')))
            ->values();
    }

    /**
     * @param  Collection<int, ApplicationBotRule>  $pending
     */
    private function rulesDiffer(Application $application, Collection $pending): bool
    {
        $application->loadMissing('botRules');

        $key = fn ($rules) => $rules
            ->map(fn ($rule) => $rule->type.':'.mb_strtolower((string) $rule->value))
            ->sort()
            ->values()
            ->all();

        return $key($application->botRules) !== $key($pending);
    }

    private function applyVhost(Application $application): ServerOpsResult
    {
        return $this->webServers->driver()->apply($application, $this->provisioner->documentRoot($application));
    }
}
