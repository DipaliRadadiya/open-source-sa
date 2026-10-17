<?php

namespace App\Actions\Server\SystemUser;

use App\Exceptions\Server\ServerOperationException;
use App\Models\SystemUser;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Delete several system users (v7's bulk delete).
 *
 * One at a time through DeleteSystemUser, so every guard it has applies to
 * each account exactly as it would alone: an account that still owns a site is
 * refused, one with a running session is refused unless `end_sessions`, and a
 * userdel failure carries its own reference. One refusal does not stop the
 * rest — the answer lists what happened to each.
 */
class DeleteSystemUsers
{
    public function __construct(private DeleteSystemUser $deleteSystemUser) {}

    /**
     * @param  iterable<SystemUser>  $systemUsers
     * @return array{succeeded: list<int>, failed: list<array{id: int, username: string, reason: string, reference?: string}>}
     */
    public function execute(iterable $systemUsers, bool $endSessions = false): array
    {
        $succeeded = [];
        $failed = [];

        foreach ($systemUsers as $systemUser) {
            try {
                $this->deleteSystemUser->execute($systemUser, $endSessions);
                $succeeded[] = $systemUser->id;
            } catch (ValidationException $e) {
                $failed[] = ['id' => $systemUser->id, 'username' => $systemUser->username, 'reason' => $this->reason($systemUser, $e)];
            } catch (ServerOperationException $e) {
                $failed[] = ['id' => $systemUser->id, 'username' => $systemUser->username, 'reason' => 'failed', 'reference' => $e->reference];
            } catch (Throwable $e) {
                report($e);
                $failed[] = ['id' => $systemUser->id, 'username' => $systemUser->username, 'reason' => 'failed'];
            }
        }

        return ['succeeded' => $succeeded, 'failed' => $failed];
    }

    /**
     * Which refusal, as a stable key rather than the sentence — the sentence is
     * already in the actor's locale, and the client words it its own way (as
     * DeleteBackups does).
     */
    private function reason(SystemUser $systemUser, ValidationException $e): string
    {
        $message = (string) ($e->errors()['system_user'][0] ?? '');

        return match ($message) {
            __('errors/system-user.has_applications') => 'has_applications',
            __('errors/system-user.has_processes', ['username' => $systemUser->username]) => 'has_processes',
            default => 'refused',
        };
    }
}
