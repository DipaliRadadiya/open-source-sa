<?php

namespace App\Services\Server\Sync\Discoverers;

use App\Contracts\Discoverable;
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
                'evidence' => ['uid' => $uid, 'home_path' => $homePath, 'shell' => $shell],
                'attributes' => ['username' => $username, 'home_path' => $homePath, 'shell' => $shell],
            ];
        }

        return $found;
    }

    public function adopt(array $item): ?object
    {
        $attributes = $item['attributes'] ?? [];

        return SystemUser::create([
            'username' => $attributes['username'] ?? $item['key'],
            'home_path' => $attributes['home_path'] ?? null,
            'shell' => $attributes['shell'] ?? '/bin/bash',
            // Both false on purpose. What the account can do is a decision the
            // panel must not infer from the fact that it exists — sudo group
            // membership is read separately, and SSH access here would claim
            // an enforcement the panel does not yet apply.
            'sudo' => false,
            'ssh_access' => false,
        ]);
    }
}
