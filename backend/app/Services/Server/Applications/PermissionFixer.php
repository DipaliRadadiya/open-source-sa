<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\FileRefusedException;
use App\Exceptions\Server\Application\FixPermissionsFailedException;
use App\Models\Application;
use App\Services\Server\Php\PoolManager;
use App\Services\Server\Php\RuntimeOwnership;
use App\Services\Server\ServerOps;

/**
 * Resets a site's ownership and modes back to the panel's defaults.
 *
 * Directories 0755 / files 0644, not tighter: nginx serves static assets as its own user (see
 * the vhost templates' `try_files`) and is not in the site's group, so 0750/0640 would make
 * them unreadable. Ownership is the isolation boundary, not read access.
 */
class PermissionFixer
{
    /**
     * Profiles the reset is safe for. Node and container trees need executable files, and the
     * bulk `chmod 0644` below would strip their executable bit, so those profiles are refused.
     *
     * Read from `serving_profile`, not `site_type` (see `ServingProfile::resolve()`). An
     * allowlist: a profile added later is refused until someone decides the reset is safe for it.
     * Not runtime-aware: executables in node_modules or vendor/bin can still need repair.
     */
    private const RESETTABLE_PROFILES = ['php', 'static'];

    public function __construct(
        private ServerOps $serverOps,
        private ApplicationProvisioner $provisioner,
        private SecretFilePrivacy $secrets,
        private PoolManager $pool,
        private RuntimeOwnership $ownership,
    ) {}

    public function fix(Application $application): void
    {
        // First, before anything touches the server: refusing after the chown would be too late.
        if (! in_array($application->serving_profile, self::RESETTABLE_PROFILES, true)) {
            throw FileRefusedException::because('application', 'unsupported_runtime', 'errors/application.permissions_fix_unsupported_runtime');
        }

        $root = $this->provisioner->documentRoot($application);
        $user = $application->systemUser->username;

        $this->run(['chown', '-R', "{$user}:{$user}", $root], $application, 'chown');
        // The chown stays root's (the user cannot do it) and is safe: `chown -R` without -H/-L
        // follows no link, and the document root's parent is immutable (SiteRootLock). The modes
        // run as the user, so a link swapped in between `find` and `chmod` reaches nothing new.
        $this->run($this->asUser($application, ['find', $root, '-type', 'd', '-exec', 'chmod', '0755', '{}', '+']), $application, 'chmod_dirs');
        $this->run($this->asUser($application, ['find', $root, '-type', 'f', '-exec', 'chmod', '0644', '{}', '+']), $application, 'chmod_files');

        // Re-tighten what the bulk pass just loosened. SecretFilePrivacy owns the mode and group
        // per site and covers every secret file (a flat 0600 here broke `.env` for sites whose PHP
        // runs as the web server's account). Never chmod these as root: GNU chmod follows a link
        // named on its command line, so a user-planted link would get root to chmod its target.
        $failure = $this->secrets->reset($application);

        if ($failure !== null) {
            throw new FixPermissionsFailedException($failure->reference, busy: $failure->busy, staleLock: $failure->staleLock, denied: $failure->denied, timedOut: $failure->timedOut);
        }

        // Every site that runs as its own user has its own session directory, pool or not (do not
        // key this on `isolated_at`: it skips OpenLiteSpeed sites). Only where it exists: a static
        // or Node site has no PHP sessions, and chmod on the missing path would fail the reset.
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

    /** Asked as the site user, like the chmod it guards. */
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
