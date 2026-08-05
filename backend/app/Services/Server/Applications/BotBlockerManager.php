<?php

namespace App\Services\Server\Applications;

use App\Enums\AiBotPolicy;
use App\Exceptions\Server\Application\BotBlockerOperationException;
use App\Models\Application;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\WebServers\WebServerManager;

/**
 * The AI Bot Blocker policy is one column, not a credentials file — changing
 * it only ever means re-rendering the vhost with a different (or no) user-
 * agent block. Same apply → test → reload → rollback shape as
 * `BasicAuthManager` and `ApplicationProvisioner::disable()`/`enable()`: a
 * failed config test restores the previous policy before failing, so a bad
 * change never leaves the vhost pointed at a broken config file.
 */
class BotBlockerManager
{
    public function __construct(
        private WebServerManager $webServers,
        private ApplicationProvisioner $provisioner,
    ) {}

    public function apply(Application $application, AiBotPolicy $policy): void
    {
        $previous = $application->ai_bot_policy;

        if ($previous === $policy) {
            return;
        }

        $application->ai_bot_policy = $policy;

        $applied = $this->applyVhost($application);

        if ($applied->failed()) {
            throw new BotBlockerOperationException($applied->reference);
        }

        if ($this->webServers->driver()->test()->failed()) {
            $application->ai_bot_policy = $previous;

            $restored = $this->applyVhost($application);

            throw new BotBlockerOperationException($restored->reference);
        }

        $this->webServers->driver()->reload();

        $application->save();
    }

    private function applyVhost(Application $application): ServerOpsResult
    {
        return $this->webServers->driver()->apply($application, $this->provisioner->documentRoot($application));
    }
}
