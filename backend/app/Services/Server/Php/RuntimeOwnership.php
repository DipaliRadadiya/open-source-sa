<?php

namespace App\Services\Server\Php;

use App\Models\Application;

/**
 * Does this site's code run as its own Linux user?
 *
 * `isolated_at` only says "has a pool": it is written by the `create_php_pool` step, which runs
 * only when {@see PoolManager::supported()} (PHP-FPM). On OpenLiteSpeed it is never set, yet each
 * vhost carries its own `extUser`, so the site still runs as its own user. Callers that need
 * "runs as its own user" (phpMyAdmin SSO, secret file ownership, session directory mode) use this.
 *
 * Not for the pool feature itself (`PoolManager`, `PoolIsolator`, `MemoryBudget`,
 * `WebRootManager::republishPool()`, the isolate endpoint): they mean "has a pool".
 */
class RuntimeOwnership
{
    public function __construct(private PhpStackManager $stacks) {}

    public function runsAsOwnUser(Application $application): bool
    {
        // A node site's systemd unit already runs as the site's own user and has no pool.
        if ($application->serving_profile !== 'php') {
            return true;
        }

        if ($application->isolated_at !== null) {
            return true;
        }

        // On LSPHP isolation comes from how the vhost is written; there is nothing to record.
        return $this->stacks->stack()->isolatesByDefault();
    }

    /**
     * The mode for a file holding this site's secrets.
     *
     * 0640 lets the group read, needed only when PHP runs as the web server's account. A site
     * running as its own user gets 0600: on OpenLiteSpeed the web server account (`nobody`) is in
     * every site user's group, so 0640 would make the file readable to it.
     */
    public function secretFileMode(Application $application): string
    {
        return $this->runsAsOwnUser($application) ? '0600' : '0640';
    }

    /**
     * The group for a file holding this site's secrets: the account that runs its PHP (the site
     * user's own group, or the web server's account). See {@see secretFileMode()}.
     */
    public function secretFileGroup(Application $application): string
    {
        return $this->runsAsOwnUser($application)
            ? (string) $application->systemUser?->username
            : (string) config('server.web_server_user', 'www-data');
    }
}
