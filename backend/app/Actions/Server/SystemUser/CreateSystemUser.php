<?php

namespace App\Actions\Server\SystemUser;

use App\Exceptions\Server\SystemUser\SystemUserCreateFailedException;
use App\Exceptions\Server\SystemUser\SystemUserPasswordFailedException;
use App\Models\SystemUser;
use App\Services\ActivityLogger;
use App\Services\Server\AccountLock;
use App\Services\Server\ServerOps;
use App\Services\Server\SystemUsers\SshUsersGroup;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreateSystemUser
{
    public function __construct(
        private ServerOps $serverOps,
        private AddSshKey $addSshKey,
        private ActivityLogger $activityLogger,
        private AccountLock $accountLock,
        private SshUsersGroup $sshUsersGroup,
    ) {}

    /**
     * @param  array{username: string, public_key?: string|null, shell?: string, sudo?: bool, ssh_access?: bool, password?: string}  $data
     */
    public function execute(array $data): SystemUser
    {
        // Serialize with every other account command: the OS lock over
        // /etc/passwd is global, so a create of one user still collides with a
        // usermod/passwd/create for another unless they run one at a time.
        //
        // sudo/ssh_access/password are folded into this same single lock
        // acquisition rather than calling ToggleSudo/ToggleSshAccess/
        // SetSystemUserPassword's own execute() — those each take the lock
        // themselves, and Laravel's atomic lock is not reentrant, so calling
        // them from inside this callback would deadlock against itself.
        return $this->accountLock->run(function () use ($data) {
            $username = $data['username'];
            $homePath = rtrim((string) config('server.home_base'), '/').'/'.$username;
            $shell = $data['shell'] ?? '/bin/bash';

            // Group membership set at creation time via useradd -G, rather
            // than a separate usermod after — one command instead of two,
            // and no window where the user briefly exists without the
            // access it was created with.
            // useradd refuses the whole command if any -G group is missing,
            // so the account would not be created at all — not merely created
            // without SSH access.
            if ($data['ssh_access'] ?? false) {
                $this->sshUsersGroup->ensure();
            }

            $groups = array_filter([
                ($data['sudo'] ?? false) ? 'sudo' : null,
                ($data['ssh_access'] ?? false) ? SshUsersGroup::NAME : null,
            ]);

            $command = ['useradd', '-m', '-s', $shell, $username];

            if ($groups !== []) {
                array_splice($command, -1, 0, ['-G', implode(',', $groups)]);
            }

            $result = $this->serverOps->run(
                $command,
                ['feature' => 'system_user', 'op' => 'create', 'system_user' => $username],
            );

            if ($result->failed()) {
                $this->activityLogger->log('system_user.create_failed', null, ['username' => $username]);
                throw new SystemUserCreateFailedException(
                    $result->reference,
                    busy: $result->busy,
                    staleLock: $result->staleLock,
                );
            }

            $systemUser = null;

            try {
                // `useradd -m` on Ubuntu 22.04+ creates the home directory at 0750.
                $traversal = $this->serverOps->run(
                    ['chmod', 'o+x', $homePath],
                    ['feature' => 'system_user', 'op' => 'grant_web_server_traversal', 'system_user' => $username],
                );

                if ($traversal->failed()) {
                    throw new SystemUserCreateFailedException($traversal->reference);
                }

                $systemUser = SystemUser::create([
                    'username' => $username,
                    'home_path' => $homePath,
                    'shell' => $shell,
                    'sudo' => (bool) ($data['sudo'] ?? false),
                    'ssh_access' => (bool) ($data['ssh_access'] ?? false),
                ]);

                if (! empty($data['public_key'])) {
                    $this->addSshKey->execute($systemUser, ['name' => 'default', 'public_key' => $data['public_key']]);
                }

                if (! empty($data['password'])) {
                    $this->setPassword($systemUser, $data['password']);
                }

                $this->activityLogger->log('system_user.created', $systemUser, ['username' => $username]);

                return $systemUser;
            } catch (Throwable $exception) {
                // useradd succeeded but a later step did not. Do not leave an
                // untracked OS account (or a panel row) behind.
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
     * Same OS mutation as `SetSystemUserPassword`, inlined rather than
     * delegated to it — see the note above about why the lock can't nest.
     */
    private function setPassword(SystemUser $systemUser, string $password): void
    {
        $result = $this->serverOps->run(
            ['chpasswd'],
            ['feature' => 'system_user', 'op' => 'password', 'system_user' => $systemUser->username],
            input: $systemUser->username.':'.$password,
        );

        if ($result->failed()) {
            $this->activityLogger->log('system_user.password_failed', $systemUser, ['username' => $systemUser->username]);
            throw new SystemUserPasswordFailedException($result->reference);
        }

        $systemUser->update(['password' => $password]);
    }
}
