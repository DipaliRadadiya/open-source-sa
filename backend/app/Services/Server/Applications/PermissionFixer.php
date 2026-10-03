<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\FixPermissionsFailedException;
use App\Models\Application;
use App\Services\Server\Php\PoolManager;
use App\Services\Server\Php\RuntimeOwnership;
use App\Services\Server\ServerOps;

/**
 * Resets a site's ownership and modes back to the panel's defaults.
 *
 * The button people actually reach for: "my site says permission denied"
 * happens far more often than anything a file browser would fix, and it is
 * newly relevant now that sites run under their own Linux user rather than
 * the shared `www-data` (see the app_php isolation work).
 *
 * Directories 0755 / files 0644, not something tighter — nginx serves static
 * assets straight off disk as its own user (see the vhost templates'
 * `try_files`), and it is not a member of the site's group. Locking the tree
 * down to 0750/0640 would make every image and script on the site
 * unreadable. Ownership is the isolation boundary here, not read access.
 */
class PermissionFixer
{
    public function __construct(
        private ServerOps $serverOps,
        private ApplicationProvisioner $provisioner,
        private SecretFilePrivacy $secrets,
        private PoolManager $pool,
        private RuntimeOwnership $ownership,
    ) {}

    public function fix(Application $application): void
    {
        $root = $this->provisioner->documentRoot($application);
        $user = $application->systemUser->username;

        $this->run(['chown', '-R', "{$user}:{$user}", $root], $application, 'chown');
        // The chown stays root's — it is the one step the user cannot do — and
        // is safe as it is: `chown -R` without -H/-L follows no link, and the
        // document root cannot itself be replaced (its parent is immutable,
        // SiteRootLock). The modes are the user's to set once they own
        // everything, and as the user a link swapped in between `find` seeing
        // a directory and `chmod` touching it reaches nothing new.
        $this->run($this->asUser($application, ['find', $root, '-type', 'd', '-exec', 'chmod', '0755', '{}', '+']), $application, 'chmod_dirs');
        $this->run($this->asUser($application, ['find', $root, '-type', 'f', '-exec', 'chmod', '0644', '{}', '+']), $application, 'chmod_files');

        // Re-tighten what the bulk pass above just loosened. Sourced from the
        // services that own each path, not duplicated here — a second copy of
        // ".env is 0600" is how it drifts, and it did: a flat 0600 here took
        // the `.env` away from a site whose PHP runs as the web server's
        // account. SecretFilePrivacy knows the mode and group per site, and
        // covers every secret file — the bulk pass left `wp-config.php` 0644.
        //
        // As the site user, apart from the one chown the user cannot do (with
        // `-h`). GNU chmod follows a link named on its command line — a user
        // who replaced `.env` with a link had root chmod whatever it pointed
        // at (reproduced live 2026-09-29 on a canary outside the site).
        $failure = $this->secrets->reset($application);

        if ($failure !== null) {
            throw new FixPermissionsFailedException($failure->reference, busy: $failure->busy, staleLock: $failure->staleLock, denied: $failure->denied, timedOut: $failure->timedOut);
        }

        // Every site that runs as its own user has a session directory of its
        // own, not just the ones with a pool. Read from `isolated_at`, this
        // skipped every OpenLiteSpeed site — leaving session files at whatever
        // the bulk chmod above left them, which is not private.
        //
        // Only where that directory exists. A static or Node site runs as its
        // own user and has no PHP sessions at all, and chmod on the missing
        // path failed the whole reset with a 500 (bug #96).
        $sessions = $this->pool->sessionPath($application);

        if ($this->ownership->runsAsOwnUser($application) && $this->isDirectory($application, $sessions)) {
            $this->run($this->asUser($application, ['chmod', '-R', '0700', $sessions]), $application, 'chmod_sessions');
        }
    }

    /**
     * @param  array<int, string>  $command
     * @return array<int, string>
     */
    private function asUser(Application $application, array $command): array
    {
        return ['runuser', '-u', $application->systemUser->username, '--', ...$command];
    }

    /**
     * Asked as the site user, like the chmod it guards: a path the user
     * cannot see is not one this reset should be touching either.
     */
    private function isDirectory(Application $application, string $path): bool
    {
        return $this->serverOps->probe(
            $this->asUser($application, ['test', '-d', $path]),
            ['feature' => 'application', 'op' => 'sessions_exist', 'application' => $application->id],
        )->ok;
    }

    /**
     * @param  array<int, string>  $command
     */
    private function run(array $command, Application $application, string $op): void
    {
        $result = $this->serverOps->run(
            $command,
            ['feature' => 'application', 'op' => $op, 'application' => $application->id],
            timeout: 120,
        );

        if ($result->failed()) {
            throw new FixPermissionsFailedException($result->reference, busy: $result->busy, staleLock: $result->staleLock, denied: $result->denied, timedOut: $result->timedOut);
        }
    }
}
