<?php

namespace App\Actions\Server\SystemUser;

use App\Exceptions\Server\SystemUser\SystemUserCreateFailedException;
use App\Exceptions\Server\SystemUser\SystemUserPasswordFailedException;
use App\Models\SystemUser;
use App\Services\ActivityLogger;
use App\Services\Server\AccountLock;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\SystemUsers\ChpasswdLine;
use App\Services\Server\SystemUsers\HomeDirectoryAccess;
use App\Services\Server\SystemUsers\SshUsersGroup;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateSystemUser
{
    public function __construct(
        private ServerOps $serverOps,
        private AddSshKey $addSshKey,
        private ActivityLogger $activityLogger,
        private AccountLock $accountLock,
        private SshUsersGroup $sshUsersGroup,
        private HomeDirectoryAccess $homeAccess,
    ) {}

    /**
     * @param  array{username: string, public_key?: string|null, shell?: string, sudo?: bool, ssh_access?: bool, password?: string}  $data
     */
    public function execute(array $data): SystemUser
    {
        // Serialize with every other account command: the OS lock over /etc/passwd is global.
        //
        // sudo/ssh_access/password are done here rather than via ToggleSudo/ToggleSshAccess/
        // SetSystemUserPassword::execute(): those take the lock themselves, and Laravel's atomic
        // lock is not reentrant, so calling them from this callback would deadlock.
        return $this->accountLock->run(function () use ($data) {
            $username = $data['username'];
            $homePath = rtrim((string) config('server.home_base'), '/').'/'.$username;
            $shell = $data['shell'] ?? '/bin/bash';

            // Groups are set by `useradd -G`, not a later usermod, so the user never exists without
            // its access. useradd refuses the whole command if a -G group is missing, so the
            // group must exist first.
            if ($data['ssh_access'] ?? false) {
                $this->sshUsersGroup->ensure();
            }

            $groups = array_filter([
                ($data['sudo'] ?? false) ? 'sudo' : null,
                ($data['ssh_access'] ?? false) ? SshUsersGroup::NAME : null,
            ]);

            $this->createOnServer($username, $homePath, $shell, $groups);

            $systemUser = null;

            try {
                $systemUser = SystemUser::create([
                    'username' => $username,
                    'home_path' => $homePath,
                    'shell' => $shell,
                    'sudo' => (bool) ($data['sudo'] ?? false),
                    'ssh_access' => (bool) ($data['ssh_access'] ?? false),
                ]);

                if (! empty($data['public_key'])) {
                    $this->addSshKey->execute($systemUser, ['name' => $this->keyName((string) $data['public_key']), 'public_key' => $data['public_key']]);
                }

                if (! empty($data['password'])) {
                    $this->setPassword($systemUser, $data['password']);
                }

                $this->activityLogger->log('system_user.created', $systemUser, ['username' => $username]);

                return $systemUser;
            } catch (Throwable $exception) {
                // useradd succeeded but a later step did not: leave no untracked OS account or row.
                $systemUser?->delete();

                $cleanup = $this->serverOps->run(
                    ['userdel', '-r', $username],
                    ['feature' => 'system_user', 'op' => 'cleanup_failed_create', 'system_user' => $username],
                );

                if ($cleanup->failed()) {
                    Log::warning('system user cleanup after failed create also failed', [
                        'username' => $username,
                        'reference' => $cleanup->reference,
                    ]);
                }

                throw $exception;
            }
        });
    }

    /**
     * Create the account on the server for a row that already exists.
     *
     * The row is recorded with its application; the *account* is a server write that belongs to
     * provisioning, which calls this.
     *
     * Idempotent: it runs from a step that re-runs, and repairs a box adopted with a populated
     * database, a rebuilt server, or a failed `useradd` whose row outlived it.
     */
    public function ensureOnServer(SystemUser $systemUser): ServerOpsResult
    {
        return $this->accountLock->run(function () use ($systemUser): ServerOpsResult {
            // `getent`, not the row: the panel's record and the server can disagree.
            $probe = $this->serverOps->run(
                ['getent', 'passwd', $systemUser->username],
                ['feature' => 'system_user', 'op' => 'account_check', 'system_user' => $systemUser->username],
                timeout: 15,
                expectedExitCodes: [2],
            );

            // Already there (including a user the operator picked).
            if ($probe->ok) {
                return $probe;
            }

            // Could not ask: not "absent", or useradd would run against a server we cannot see.
            if (! $probe->answered) {
                return $probe;
            }

            $groups = array_filter([
                $systemUser->sudo ? 'sudo' : null,
                $systemUser->ssh_access ? SshUsersGroup::NAME : null,
            ]);

            if ($systemUser->ssh_access) {
                $this->sshUsersGroup->ensure();
            }

            $this->createOnServer(
                $systemUser->username,
                (string) $systemUser->home_path,
                (string) ($systemUser->shell ?: '/bin/bash'),
                $groups,
            );

            // A password chosen on the create-application form arrives on the row.
            if (filled($systemUser->password)) {
                $this->setPassword($systemUser, (string) $systemUser->password);
            }

            $this->activityLogger->log('system_user.created', $systemUser, ['username' => $systemUser->username]);

            // Probe again: the first probe was a miss, which would read as failure for work that
            // succeeded, and the step must pass only if the account is really there now.
            return $this->serverOps->run(
                ['getent', 'passwd', $systemUser->username],
                ['feature' => 'system_user', 'op' => 'account_confirm', 'system_user' => $systemUser->username],
                timeout: 15,
            );
        });
    }

    /**
     * `useradd` and the one permission fix it needs, with no database writes.
     *
     * Shared by `execute()` and `ensureOnServer()` so both create the same account (shell, home,
     * groups, traversal bit).
     *
     * @param  array<int, string>  $groups
     */
    private function createOnServer(string $username, string $homePath, string $shell, array $groups): void
    {
        $command = ['useradd', '-m', '-s', $shell, $username];

        if ($groups !== []) {
            array_splice($command, -1, 0, ['-G', implode(',', $groups)]);
        }

        $result = $this->serverOps->run(
            $command,
            ['feature' => 'system_user', 'op' => 'create', 'system_user' => $username],
        );

        // Exit 9 is useradd's "name already in use", by a user OR a group (`ssh-users`, `lsadm`,
        // `panel`). The name is only unique in the panel's table: the input to change, not a fault.
        if ($result->exitCode() === 9) {
            throw ValidationException::withMessages([
                'username' => [__('errors/system-user.username_taken_on_server')],
            ]);
        }

        if ($result->failed()) {
            $this->activityLogger->log('system_user.create_failed', null, ['username' => $username]);
            throw new SystemUserCreateFailedException(
                $result->reference,
                busy: $result->busy,
                staleLock: $result->staleLock,
                denied: $result->denied,
            );
        }

        // `useradd -m` on Ubuntu 22.04+ creates the home at 0750, which the web server and panel
        // cannot enter. Never `chmod o+x` (every local account gets in too): they are let in by
        // ACL, falling back to `o+x` only without setfacl. {@see HomeDirectoryAccess}
        $fallback = $this->homeAccess->closeNewHome($username, $homePath);

        if ($fallback?->failed()) {
            throw new SystemUserCreateFailedException($fallback->reference);
        }
    }

    /**
     * Same OS mutation as `SetSystemUserPassword`, inlined because the lock cannot nest.
     */
    private function setPassword(SystemUser $systemUser, string $password): void
    {
        $result = $this->serverOps->run(
            ['chpasswd'],
            ['feature' => 'system_user', 'op' => 'password', 'system_user' => $systemUser->username],
            input: ChpasswdLine::for($systemUser->username, $password),
        );

        if ($result->failed()) {
            $this->activityLogger->log('system_user.password_failed', $systemUser, ['username' => $systemUser->username]);
            throw new SystemUserPasswordFailedException($result->reference);
        }

        $systemUser->update(['password' => $password]);
    }

    /**
     * The key's own comment (`user@laptop`) as its label, or the key type when it has none.
     */
    private function keyName(string $publicKey): string
    {
        $parts = preg_split('/\s+/', trim($publicKey), 3) ?: [];
        $comment = trim((string) ($parts[2] ?? ''));

        return mb_substr($comment !== '' ? $comment : (string) ($parts[0] ?? 'ssh-key'), 0, 255);
    }
}
