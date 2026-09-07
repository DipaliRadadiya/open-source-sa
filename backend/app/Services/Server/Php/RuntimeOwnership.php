<?php

namespace App\Services\Server\Php;

use App\Models\Application;

/**
 * Does this site's code run as its own Linux user?
 *
 * One question, asked in three places that each used to answer it themselves
 * with the same expression:
 *
 *     $application->serving_profile !== 'php' || $application->isolated_at !== null
 *
 * That expression is right about two thirds of the world and wrong about the
 * third. `isolated_at` is written by the `create_php_pool` step, which runs
 * only when {@see PoolManager::supported()} — the PHP-FPM stack. **On
 * OpenLiteSpeed it is never set, on any site, ever**, because there are no
 * pools there: each vhost carries its own `extUser`, so an OLS site runs as
 * its own user from the moment it is written. The column says "has a pool";
 * all three callers were reading it as "runs as its own user".
 *
 * What that cost, before this existed:
 *
 *  - **phpMyAdmin could not be opened at all on an OpenLiteSpeed server.**
 *    {@see PhpMyAdminSso::canIssue()} refused every database with
 *    `phpmyadmin_not_isolated` — a whole feature dead on one stack, reported
 *    as "the button is disabled for this database" when it was disabled for
 *    all of them.
 *  - Secret files (`wp-config.php`, `.env`) were group-owned by `www-data` on
 *    OLS, an account that does not run PHP there — breaking the rule
 *    {@see AbstractSiteInstaller::runtimePhpOwner()}'s own docblock states:
 *    owner is the site user, group is whoever runs PHP.
 *  - The session directory was never tightened to 0700 by
 *    {@see PermissionFixer}, so an OLS site's session files kept whatever the
 *    bulk chmod left them at.
 *
 * Deliberately NOT used by the pool feature itself. `PoolManager`,
 * `PoolIsolator`, `MemoryBudget`, `WebRootManager::republishPool()` and the
 * isolate endpoint all genuinely mean "has a pool", and reading them as this
 * question would have them build pools on a stack that has none.
 */
class RuntimeOwnership
{
    public function __construct(private PhpStackManager $stacks) {}

    public function runsAsOwnUser(Application $application): bool
    {
        // A node site's systemd unit already runs as the site's own user, and
        // it has no pool to be isolated by — so the column is null for one
        // forever, and asking about it is asking the wrong question twice.
        if ($application->serving_profile !== 'php') {
            return true;
        }

        if ($application->isolated_at !== null) {
            return true;
        }

        // The stack answers for itself. On LSPHP isolation is a property of
        // how the vhost is written rather than a feature to switch on, so
        // there is nothing to record and nothing to wait for.
        return $this->stacks->stack()->isolatesByDefault();
    }
}
