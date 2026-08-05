<?php

namespace App\Actions\Server\Application;

use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationFail2banManager;
use Throwable;

class UpdateApplicationFail2ban
{
    public function __construct(
        private ApplicationFail2banManager $fail2ban,
        private ActivityLogger $activityLogger,
    ) {}

    public function execute(Application $application, bool $enabled): void
    {
        if ($application->fail2ban_enabled === $enabled) {
            return;
        }

        $previous = $application->fail2ban_enabled;

        $application->fail2ban_enabled = $enabled;
        $application->save();

        try {
            // `fail2ban-client` has no `-t`-style dry run the way nginx/Apache
            // do — `reload` either works or it doesn't, so the same
            // apply-then-test-then-reload verification other features this
            // week rely on isn't available here. Revert the column on
            // failure instead, so a rejected reload never leaves the
            // database claiming a state the server never actually reached.
            $this->fail2ban->sync();
        } catch (Throwable $e) {
            $application->fail2ban_enabled = $previous;
            $application->save();

            throw $e;
        }

        $this->activityLogger->log($enabled ? 'application.fail2ban_enabled' : 'application.fail2ban_disabled', $application, [
            'name' => $application->name,
        ]);
    }
}
