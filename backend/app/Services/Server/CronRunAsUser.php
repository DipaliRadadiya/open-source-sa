<?php

namespace App\Services\Server;

use App\Models\SystemUser;
use Illuminate\Validation\ValidationException;

/**
 * Resolves which OS account a cron job runs as.
 *
 * A job targets either a panel System User or a raw account the panel does not
 * manage, and the answer has to survive into the `USER` column of the cron.d
 * file — a name no account owns produces a file cron parses, accepts, and then
 * fails to run, silently, every minute.
 *
 * Shared by create and update rather than written twice: update gained the
 * ability to change the account later, and a second copy of this check is a
 * second chance for the two to disagree about what a valid account is.
 */
class CronRunAsUser
{
    public function __construct(private CrontabManager $crontab) {}

    /**
     * @return array{username: string, system_user_id: int|null}
     */
    public function resolve(?int $systemUserId, ?string $username): array
    {
        $systemUser = $systemUserId !== null ? SystemUser::find($systemUserId) : null;
        $resolved = $systemUser?->username ?? $username;

        if ($resolved === null || ! $this->crontab->userExists($resolved)) {
            throw ValidationException::withMessages([
                'username' => [__('errors/cronjob.invalid_user')],
            ]);
        }

        return ['username' => $resolved, 'system_user_id' => $systemUser?->id];
    }
}
