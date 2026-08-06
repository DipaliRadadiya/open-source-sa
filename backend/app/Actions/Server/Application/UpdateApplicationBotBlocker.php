<?php

namespace App\Actions\Server\Application;

use App\Enums\AiBotPolicy;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\BotBlockerManager;

class UpdateApplicationBotBlocker
{
    public function __construct(
        private BotBlockerManager $botBlocker,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @param  array<int, string>|null  $blocked
     * @param  array<int, string>|null  $allowed
     */
    public function execute(
        Application $application,
        AiBotPolicy $policy,
        ?array $blocked = null,
        ?array $allowed = null,
    ): void {
        $application->load('systemUser', 'botRules');

        $previousPolicy = $application->ai_bot_policy;
        // Compared as a signature rather than a count: swapping one agent for
        // another changes the rules without changing how many there are.
        $previousRules = $this->signature($application);

        $this->botBlocker->apply($application, $policy, $blocked, $allowed);

        $application->load('botRules');

        $policyChanged = $previousPolicy !== $application->ai_bot_policy;
        $rulesChanged = $previousRules !== $this->signature($application);

        // The manager treats an unchanged request as a no-op, so nothing is
        // logged for a form that was saved without being edited.
        if (! $policyChanged && ! $rulesChanged) {
            return;
        }

        if ($policyChanged) {
            $this->activityLogger->log('application.ai_bot_policy_updated', $application, [
                'name' => $application->name,
                'policy' => $policy->title(),
            ]);
        }

        if ($rulesChanged) {
            $this->activityLogger->log('application.bot_rules_updated', $application, [
                'name' => $application->name,
                'blocked' => $application->botRules->where('type', 'block')->count(),
                'allowed' => $application->botRules->where('type', 'allow')->count(),
            ]);
        }
    }

    /** A stable, order-independent fingerprint of this site's bot rules. */
    private function signature(Application $application): string
    {
        return $application->botRules
            ->map(fn ($rule) => $rule->type.':'.mb_strtolower((string) $rule->value))
            ->sort()
            ->implode('|');
    }
}
