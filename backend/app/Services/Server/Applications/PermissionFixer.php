<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\FixPermissionsFailedException;
use App\Models\Application;
use App\Services\Server\Php\PoolManager;
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
        private ApplicationEnvironment $environment,
        private PoolManager $pool,
    ) {}

    public function fix(Application $application): void
    {
        $root = $this->provisioner->documentRoot($application);
        $user = $application->systemUser->username;

        $this->run(['chown', '-R', "{$user}:{$user}", $root], $application, 'chown');
        $this->run(['find', $root, '-type', 'd', '-exec', 'chmod', '0755', '{}', '+'], $application, 'chmod_dirs');
        $this->run(['find', $root, '-type', 'f', '-exec', 'chmod', '0644', '{}', '+'], $application, 'chmod_files');

        // Re-tighten what the bulk pass above just loosened. Sourced from the
        // services that own each path, not duplicated here — a second copy of
        // ".env is 0600" is how it drifts.
        if ($this->environment->exists($application)) {
            $this->run(['chmod', '0600', $this->environment->path($application)], $application, 'chmod_env');
        }

        if ($application->isolated_at !== null) {
            $this->run(['chmod', '-R', '0700', $this->pool->sessionPath($application)], $application, 'chmod_sessions');
        }
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
            throw new FixPermissionsFailedException($result->reference, busy: $result->busy, staleLock: $result->staleLock);
        }
    }
}
