<?php

namespace App\Services\Server\Applications;

use App\Enums\ApplicationStatus;
use App\Exceptions\Server\Application\WebRootOperationException;
use App\Models\Application;
use App\Services\Server\Php\PoolManager;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\WebServers\WebServerManager;

/**
 * Move which directory of a site is actually served.
 *
 * The column and the validation for it already existed; what did not was
 * anything that made the change *true*. Storing `web_root` and returning 200
 * left the server serving the old directory until someone re-provisioned —
 * the panel reporting a change it had not made, which is the one thing this
 * panel is not allowed to do.
 *
 * The document root is not just the vhost's `root`. Three other things are
 * derived from it, and all three break quietly if only the vhost moves:
 *
 *  - the PHP-FPM pool's `session.save_path` and error log,
 *  - the systemd unit's `WorkingDirectory` for a Node app,
 *  - `.panel/.htpasswd`, which Password Protection points the vhost at.
 *
 * Ordering is the safety property, same as everywhere else in this namespace:
 * everything the *new* config will reference is put in place first, the vhost
 * is applied → tested → reloaded with a rollback on a failed test, and only
 * then is the old sidecar removed. At no instant does a live config reference
 * a file that is not there.
 */
class WebRootManager
{
    public function __construct(
        private WebServerManager $webServers,
        private ApplicationProvisioner $provisioner,
        private BasicAuthManager $basicAuth,
        private ProcessSupervisor $supervisor,
        private PoolManager $pools,
        private ServerOps $serverOps,
    ) {}

    /**
     * @throws WebRootOperationException
     */
    public function apply(Application $application, ?string $webRoot): void
    {
        $application->loadMissing('systemUser');

        $previous = $this->normalize($application->web_root);
        $next = $this->normalize($webRoot);

        if ($previous === $next) {
            return;
        }

        // Nothing has been written to the server for these, so there is no
        // config to move — storing the value is the whole change. A disabled
        // site is in the same position for a different reason: its vhost
        // deliberately points at the disabled page, and re-applying the real
        // one here would put the site back online as a side effect of an
        // unrelated setting.
        if ($application->status !== ApplicationStatus::Active || $application->disabled_at !== null) {
            $application->web_root = $next;
            $application->save();

            return;
        }

        $previousCredentials = $this->basicAuth->credentialsPath($application);

        $application->web_root = $next;

        $documentRoot = $this->provisioner->documentRoot($application);

        // The new directory has to exist and be owned before anything points
        // at it: a vhost whose root is missing answers every request with a
        // 403, which looks exactly like a permissions bug and is not one.
        $this->prepareDirectory($application, $documentRoot);

        // Written while the old file is still in place, so whichever config is
        // live at any moment has its credential file present.
        $this->basicAuth->publish($application);

        // The pool before the vhost, for the same reason isolating a site
        // writes the pool first: the vhost is what starts sending requests
        // into it, so it must be the last thing to change.
        $this->republishPool($application);

        $applied = $this->applyVhost($application);

        if ($applied->failed()) {
            $this->rollback($application, $previous);

            throw new WebRootOperationException($applied->reference);
        }

        $test = $this->webServers->driver()->test();

        if ($test->failed()) {
            $this->rollback($application, $previous);

            throw new WebRootOperationException($test->reference);
        }

        $this->webServers->driver()->reload();

        $application->save();

        // After the reload: the unit's working directory only matters to the
        // next start, and the old credential file must outlive the config that
        // referenced it.
        $this->republishUnit($application, $documentRoot);

        if ($application->basic_auth_enabled && $previousCredentials !== $this->basicAuth->credentialsPath($application)) {
            $this->serverOps->run(
                ['rm', '-f', $previousCredentials],
                $this->context($application, 'web_root_remove_stale_credentials'),
            );
        }
    }

    /**
     * Put the previous web root back on the model and in the live config.
     *
     * Nothing has been reloaded at either call site, so the server is still
     * serving the old root — this restores the *files* to match, and leaves
     * the freshly created directory behind rather than removing it, because a
     * `rm -rf` on a path derived from user input is not a rollback anyone
     * should write.
     */
    private function rollback(Application $application, string $previous): void
    {
        $application->web_root = $previous;

        $this->republishPool($application);
        $this->applyVhost($application);
        $this->basicAuth->publish($application);
    }

    private function prepareDirectory(Application $application, string $documentRoot): void
    {
        $user = $application->systemUser?->username;

        $created = $this->serverOps->run(
            ['mkdir', '-p', $documentRoot.'/.panel'],
            $this->context($application, 'web_root_mkdir'),
        );

        if ($created->failed()) {
            throw new WebRootOperationException($created->reference);
        }

        if ($user === null) {
            return;
        }

        // Not recursive: the target may already hold the user's files, and a
        // `chown -R` over a directory this call did not create is a bigger
        // claim than moving a web root should make.
        $owned = $this->serverOps->run(
            ['chown', "{$user}:{$user}", $documentRoot, $documentRoot.'/.panel'],
            $this->context($application, 'web_root_chown'),
        );

        if ($owned->failed()) {
            throw new WebRootOperationException($owned->reference);
        }
    }

    /**
     * Only an isolated site has a pool of its own to rewrite. A shared-pool
     * site keeps sessions wherever the shared pool puts them, which the web
     * root does not move.
     */
    private function republishPool(Application $application): void
    {
        if ($application->isolated_at === null || ! $this->pools->supported()) {
            return;
        }

        $settings = $application->phpSettings;

        if ($settings === null) {
            return;
        }

        $result = $this->pools->apply($application, $settings);

        if (! $result['ok']) {
            throw new WebRootOperationException((string) $result['reference']);
        }
    }

    /**
     * A Node app's unit runs from the document root, so a moved root means the
     * unit is now pointed at the wrong directory. Rewritten without starting
     * anything: a stopped app must not be started by a settings change.
     */
    private function republishUnit(Application $application, string $documentRoot): void
    {
        if (! $this->supervisor->runs($application)) {
            return;
        }

        $this->supervisor->apply($application, $documentRoot, start: false);
    }

    private function applyVhost(Application $application): ServerOpsResult
    {
        return $this->webServers->driver()->apply($application, $this->provisioner->documentRoot($application));
    }

    /**
     * Stored without a leading or trailing slash, so `/public`, `public/` and
     * `public` are the same web root and a no-op change is recognised as one.
     */
    private function normalize(?string $webRoot): string
    {
        $trimmed = trim((string) ($webRoot ?? ''), '/');

        return $trimmed === '' ? '/' : $trimmed;
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Application $application, string $op): array
    {
        return ['feature' => 'application', 'op' => $op, 'application' => $application->id];
    }
}
