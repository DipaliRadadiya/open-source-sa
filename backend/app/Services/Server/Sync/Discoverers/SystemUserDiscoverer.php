<?php

namespace App\Services\Server\Sync\Discoverers;

use App\Contracts\Discoverable;
use App\Enums\SyncMode;
use App\Http\Requests\Server\SystemUser\StoreSystemUserRequest;
use App\Models\SyncRun;
use App\Models\SystemUser;
use App\Services\Server\ServerOps;

/**
 * Login accounts on the box that the panel does not know about.
 *
 * Read from `getent passwd` rather than by listing /home: a directory under
 * /home proves nothing about whether an account still exists, and an account
 * whose home was moved would be missed entirely.
 *
 * The filter is deliberately conservative. Adopting a system account would
 * put root or www-data in a list the panel offers to delete, so the bar is:
 * a real login UID, a home under the configured base, and a name that is not
 * one the panel itself refuses to create.
 */
class SystemUserDiscoverer implements Discoverable
{
    /**
     * Below this are system accounts on every Debian-derived distribution.
     * `nobody` sits at 65534 and is not a person either.
     */
    private const MIN_UID = 1000;

    private const MAX_UID = 60000;

    /** Groups that grant sudo in a stock Debian/Ubuntu sudoers. */
    private const SUDO_GROUPS = ['sudo', 'admin'];

    public function __construct(private ServerOps $serverOps) {}

    public function resourceType(): string
    {
        return 'system_user';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function discover(SyncRun $run): array
    {
        $result = $this->serverOps->run(
            ['getent', 'passwd'],
            ['feature' => 'sync', 'op' => 'discover_system_users'],
            timeout: 30,
        );

        if ($result->failed()) {
            return [];
        }

        $sudoers = $this->sudoGroupMembers();

        // Null means the groups could not be read. Correcting from that would
        // turn every account's sudo off in the panel's record, so it is left
        // alone and new accounts are adopted without sudo, as before.
        if ($sudoers !== null && $run->mode === SyncMode::Apply) {
            $this->correctTrackedSudo($sudoers);
        }

        $sudoers ??= [];

        $tracked = SystemUser::query()->pluck('username')->map('strtolower')->all();
        $home = rtrim((string) config('server.home_base', '/home'), '/');
        $found = [];

        foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $line) {
            // name:x:uid:gid:gecos:home:shell
            $parts = explode(':', $line);

            if (count($parts) < 7) {
                continue;
            }

            [$username, , $uid, , , $homePath, $shell] = $parts;
            $uid = (int) $uid;

            if ($uid < self::MIN_UID || $uid > self::MAX_UID) {
                continue;
            }

            // Only accounts living where this panel puts them. A developer's
            // own login under /root or /var/lib is not a site owner, and
            // offering to manage it would be presumptuous.
            if (! str_starts_with($homePath, $home.'/')) {
                continue;
            }

            if (in_array($username, StoreSystemUserRequest::RESERVED, true)) {
                continue;
            }

            if (in_array(strtolower($username), $tracked, true)) {
                continue;
            }

            $found[] = [
                'key' => $username,
                'label' => $username,
                'confidence' => 100,
                'evidence' => ['uid' => $uid, 'home_path' => $homePath, 'shell' => $shell, 'sudo' => in_array($username, $sudoers, true)],
                'attributes' => ['username' => $username, 'home_path' => $homePath, 'shell' => $shell, 'sudo' => in_array($username, $sudoers, true)],
            ];
        }

        return $found;
    }

    /**
     * Who is in the groups that grant sudo on a Debian-derived server.
     *
     * `sudo` is the group the panel's own toggle adds to and removes from;
     * `admin` is the older Ubuntu equivalent and is still granted in the
     * default sudoers. Grants written straight into /etc/sudoers.d are not
     * read — the panel cannot reproduce or revoke those, and guessing from a
     * sudoers file is worse than not claiming anything.
     *
     * Only lines whose group name is one of these count: `getent` answers
     * with nothing but the groups asked for, and anything else is not a
     * membership list.
     *
     * Null when the answer did not include the `sudo` group at all — it
     * exists on every Debian-derived install, so its absence means the
     * command failed, not that nobody has sudo.
     *
     * @return array<int, string>|null
     */
    private function sudoGroupMembers(): ?array
    {
        $result = $this->serverOps->run(
            ['getent', 'group', ...self::SUDO_GROUPS],
            ['feature' => 'sync', 'op' => 'discover_sudo_members'],
            timeout: 30,
        );

        // Exit 2 when one of the groups does not exist (no `admin` on a new
        // install) — the lines that did resolve are still printed.
        $members = [];
        $sawSudo = false;

        foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $line) {
            $parts = explode(':', trim($line));

            if (count($parts) !== 4 || ! in_array($parts[0], self::SUDO_GROUPS, true)) {
                continue;
            }

            $sawSudo = $sawSudo || $parts[0] === 'sudo';

            if ($parts[3] !== '') {
                array_push($members, ...explode(',', $parts[3]));
            }
        }

        return $sawSudo ? array_values(array_unique($members)) : null;
    }

    /**
     * Bring the recorded sudo flag of accounts already in the panel back in
     * line with the server, when somebody changed it outside the panel.
     *
     * Apply runs only — a preview promises to write nothing. The panel's
     * record moves; the server is never touched.
     *
     * @param  array<int, string>  $sudoers
     */
    private function correctTrackedSudo(array $sudoers): void
    {
        SystemUser::query()->each(function (SystemUser $user) use ($sudoers) {
            $actual = in_array($user->username, $sudoers, true);

            if ($user->sudo !== $actual) {
                $user->forceFill(['sudo' => $actual])->save();
            }
        });
    }

    public function adopt(array $item): ?object
    {
        $attributes = $item['attributes'] ?? [];

        return SystemUser::create([
            'username' => $attributes['username'] ?? $item['key'],
            'home_path' => $attributes['home_path'] ?? null,
            'shell' => $attributes['shell'] ?? '/bin/bash',
            // What the server says, not a default. This was hard-coded false
            // under a comment claiming group membership was "read
            // separately" — it was not read anywhere, so an account with full
            // sudo was listed as having none. Nothing changed on the server;
            // the screen was simply wrong about a root-equivalent account, and
            // switching sudo "on and off again" to fix it would have removed
            // a grant somebody set up on purpose.
            'sudo' => (bool) ($attributes['sudo'] ?? false),
            // Still false: SSH access here would claim an enforcement the
            // panel does not yet apply.
            'ssh_access' => false,
        ]);
    }
}
