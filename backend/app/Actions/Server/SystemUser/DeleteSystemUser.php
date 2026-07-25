<?php

namespace App\Actions\Server\SystemUser;

use App\Exceptions\Server\SystemUser\SystemUserDeleteFailedException;
use App\Models\SystemUser;
use App\Services\ActivityLogger;
use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class DeleteSystemUser
{
    public function __construct(
        private ServerOps $serverOps,
        private ActivityLogger $activityLogger,
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

            $systemUser->delete();
        });
    }
}
