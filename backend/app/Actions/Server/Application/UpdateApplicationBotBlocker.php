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

    public function execute(Application $application, AiBotPolicy $policy): void
    {
        $application->load('systemUser');

        if ($application->ai_bot_policy === $policy) {
            return;
        }

        $this->botBlocker->apply($application, $policy);

        $this->activityLogger->log('application.ai_bot_policy_updated', $application, [
            'name' => $application->name,
            'policy' => $policy->title(),
        ]);
    }
}
