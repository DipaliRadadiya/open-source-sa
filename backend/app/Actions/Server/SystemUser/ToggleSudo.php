<?php

namespace App\Actions\Server\SystemUser;

use App\Exceptions\Server\SystemUser\SystemUserSudoFailedException;
use App\Models\SystemUser;
use App\Services\ActivityLogger;
use App\Services\Server\AccountLock;
use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ToggleSudo
{
    /**
     * Groups that grant sudo on a Debian-derived server: `sudo`, and `admin`,
     * the older Ubuntu one still granted by the default sudoers (servers set
     * up by hand or moved from v7). Server Sync reads both; turning sudo off
     * has to leave both, or the panel says "off" while `%admin` still grants it.
     */
    public const SUDO_GROUPS = ['sudo', 'admin'];

    public function __construct(
        private ServerOps $serverOps,
        private ActivityLogger $activityLogger,
        private AccountLock $accountLock,
    ) {}

    public function execute(SystemUser $systemUser, bool $sudo): void
    {
        // usermod/gpasswd take the global /etc/group lock — serialize with
        // every other account command.
        $this->accountLock->run(function () use ($systemUser, $sudo) {
            $sudo ? $this->grant($systemUser) : $this->revoke($systemUser);

            $systemUser->update(['sudo' => $sudo]);

            $this->activityLogger->log(
                $sudo ? 'system_user.sudo_enabled' : 'system_user.sudo_disabled',
                $systemUser,
                ['username' => $systemUser->username],
            );
        });
    }

    private function grant(SystemUser $systemUser): void
    {
        $result = $this->serverOps->run(
            ['usermod', '-aG', 'sudo', $systemUser->username],
            ['feature' => 'system_user', 'op' => 'sudo.enable', 'system_user' => $systemUser->username],
        );

        if ($result->failed()) {
            throw new SystemUserSudoFailedException($result->reference);
        }
    }

    /**
     * Leave every sudo-granting group the user is in, then check that none is
     * left. A user only in `admin` used to get `gpasswd -d user sudo`, which
     * fails ("not a member"); one in both kept `admin` and with it sudo
     * (FS-C22 #16, 2026-10-23).
     */
    private function revoke(SystemUser $systemUser): void
    {
        $context = ['feature' => 'system_user', 'op' => 'sudo.disable', 'system_user' => $systemUser->username];

        foreach ($this->sudoGroups($systemUser, $context) as $group) {
            $result = $this->serverOps->run(
                ['gpasswd', '-d', $systemUser->username, $group],
                $context + ['group' => $group],
                // 3: not a member — already out of it is out of it.
                expectedExitCodes: [3],
            );

            if (! $result->answered) {
                throw new SystemUserSudoFailedException($result->reference);
            }
        }

        $left = $this->sudoGroups($systemUser, $context);

        if ($left !== []) {
            $reference = (string) Str::uuid();

            Log::channel('server-ops')->warning('sudo still granted after leaving the sudo groups', $context + [
                'groups' => $left,
                'reference' => $reference,
            ]);

            throw new SystemUserSudoFailedException($reference);
        }
    }

    /**
     * The sudo-granting groups the user is in now, read from the server.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, string>
     */
    private function sudoGroups(SystemUser $systemUser, array $context): array
    {
        $result = $this->serverOps->run(['id', '-nG', $systemUser->username], $context + ['step' => 'read_groups']);

        if ($result->failed()) {
            throw new SystemUserSudoFailedException($result->reference);
        }

        $groups = preg_split('/\s+/', trim($result->output())) ?: [];

        return array_values(array_intersect(self::SUDO_GROUPS, $groups));
    }
}
