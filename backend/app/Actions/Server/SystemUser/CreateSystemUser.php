<?php

namespace App\Actions\Server\SystemUser;

use App\Exceptions\Server\SystemUser\SystemUserCreateFailedException;
use App\Exceptions\Server\SystemUser\SystemUserPasswordFailedException;
use App\Models\SystemUser;
use App\Services\ActivityLogger;
use App\Services\Server\AccountLock;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
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
     * Create the account on the server for a row that already exists.
     *
     * The panel records an application's owner when the application is
     * recorded, in the same transaction, so that a site and its user are one
     * fact rather than two. The *account* is a server write, and server writes
     * belong to provisioning — this is what lets the provisioner make it.
     *
     * Idempotent on purpose. It is called from a step that re-runs, and it is
     * the repair for three states the provisioner used to only complain about:
     * a box adopted with a populated database, a server rebuilt underneath one,
     * and a `useradd` that failed somewhere the row outlived.
     */
    public function ensureOnServer(SystemUser $systemUser): ServerOpsResult
    {
        return $this->accountLock->run(function () use ($systemUser): ServerOpsResult {
            // `getent`, not the row: the row is what the panel believes, and
            // the whole reason this method exists is that the two can disagree.
            $probe = $this->serverOps->run(
                ['getent', 'passwd', $systemUser->username],
                ['feature' => 'system_user', 'op' => 'account_check', 'system_user' => $systemUser->username],
                timeout: 15,
                expectedExitCodes: [2],
            );

            // Already there — including the ordinary case of a user the
            // operator picked rather than one the panel generated.
            if ($probe->ok) {
                return $probe;
            }

            // Could not ask. Reported rather than treated as "absent", because
            // the answer to that would be a useradd against a server we cannot
            // see, and the step should fail with its own reference instead.
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

            $this->activityLogger->log('system_user.created', $systemUser, ['username' => $systemUser->username]);

            // Asked again rather than assuming. The caller is a provisioning
            // step whose whole job is "this account exists", and returning the
            // *first* probe would hand it the miss that started all this — a
            // failed result for work that succeeded. Re-probing also means the
            // step passes only if the account is genuinely there now.
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
     * Shared by `execute()` and `ensureOnServer()` so the account a
     * provisioning run creates is identical to the account the System Users
     * screen creates — shell, home, groups and traversal bit.
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

        if ($result->failed()) {
            $this->activityLogger->log('system_user.create_failed', null, ['username' => $username]);
            throw new SystemUserCreateFailedException(
                $result->reference,
                busy: $result->busy,
                staleLock: $result->staleLock,
                denied: $result->denied,
            );
        }

        // `useradd -m` on Ubuntu 22.04+ creates the home directory at 0750, so
        // the web server cannot traverse into it.
        $traversal = $this->serverOps->run(
            ['chmod', 'o+x', $homePath],
            ['feature' => 'system_user', 'op' => 'grant_web_server_traversal', 'system_user' => $username],
        );

        if ($traversal->failed()) {
            throw new SystemUserCreateFailedException($traversal->reference);
        }
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
