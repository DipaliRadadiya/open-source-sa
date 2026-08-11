<?php

namespace App\Services\Server\SystemUsers;

use App\Services\Server\ServerOps;

/**
 * The `ssh-users` group the SSH-access toggle moves accounts in and out of.
 *
 * Nothing created it. `useradd -G ssh-users` and `usermod -aG ssh-users`
 * both fail outright against a group that does not exist, so on a real
 * server every attempt to create a system user with SSH access, or to switch
 * it on afterwards, returned a 500 — while the whole suite passed, because
 * `Process` is faked and a faked command never checks whether its arguments
 * mean anything. Same shape as the sudoers bug in August: consistent with
 * itself, wrong against the machine.
 *
 * Callers must already hold the account lock — `groupadd` takes the same
 * /etc/group lock every other account command does, and Laravel's atomic
 * lock is not reentrant.
 */
class SshUsersGroup
{
    public const NAME = 'ssh-users';

    public function __construct(private ServerOps $serverOps) {}

    /**
     * Create the group if it is not there yet.
     *
     * `-f` is what makes this safe to call on every grant: it exits 0 when
     * the group already exists instead of failing, so there is no
     * check-then-create race with another panel request doing the same thing.
     */
    public function ensure(): void
    {
        $this->serverOps->run(
            ['groupadd', '-f', self::NAME],
            ['feature' => 'system_user', 'op' => 'ssh_group_ensure'],
        );
    }
}
