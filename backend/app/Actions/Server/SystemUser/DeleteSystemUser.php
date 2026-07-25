<?php

namespace App\Actions\Server\SystemUser;

use App\Exceptions\Server\SystemUser\SystemUserDeleteFailedException;
use App\Models\SystemUser;
use App\Services\ActivityLogger;
use App\Services\Server\CrontabManager;
use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class DeleteSystemUser
{
    public function __construct(
        private ServerOps $serverOps,
        private ActivityLogger $activityLogger,
        private CrontabManager $crontab,
    ) {}

    public function execute(SystemUser $systemUser): void
    {
        // Can't orphan running applications.
        if ($systemUser->applications()->exists()) {
            throw ValidationException::withMessages([
                'system_user' => [__('errors/system-user.has_applications')],
            ]);
        }

        Cache::lock('system-user:delete:'.$systemUser->username, 15)->block(5, function () use ($systemUser) {
            $result = $this->serverOps->run(
                ['userdel', '-r', $systemUser->username],
                ['feature' => 'system_user', 'op' => 'delete', 'system_user' => $systemUser->username],
            );

            if ($result->failed()) {
                $this->activityLogger->log('system_user.delete_failed', $systemUser, ['username' => $systemUser->username]);
                throw new SystemUserDeleteFailedException($result->reference);
            }

            $this->activityLogger->log('system_user.deleted', $systemUser, ['username' => $systemUser->username]);

            // Remove each cron job's /etc/cron.d file before the DB cascade drops
            // the rows — otherwise the files would be orphaned and would point at
            // a now-deleted OS user.
            foreach ($systemUser->cronjobs as $cronjob) {
                $this->crontab->remove($cronjob);
            }

            $systemUser->delete();
        });
    }
}
