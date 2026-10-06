<?php

namespace App\Services\Server\Applications;

use App\Enums\ApplicationStatus;
use App\Exceptions\Server\Application\WebRootOperationException;
use App\Models\Application;
use App\Services\Server\Php\PoolManager;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Validation\ValidationException;

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
     * @throws ValidationException when a live site is pointed at a folder that is not there
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

        $application->web_root = $next;

        $documentRoot = $this->provisioner->documentRoot($application);

        $this->refuseLinks($application, $documentRoot, $previous);
        $this->refuseMissing($application, $documentRoot, $previous);

        // No `mkdir` and no `chown` here any more (WR-01). Both ran as root on
        // a path inside the site user's own public_html, and both follow
        // symlinks: a link the user planted (public_html/pub -> /etc) handed
        // the target to the user. They had stopped doing anything useful once
        // the folder had to exist already (#8) — it is the user's folder, made
        // by the user or by a deploy that runs as the user.

        // The credential file no longer moves with the document root — it
        // lives above it now — so this is only here to guarantee it exists
        // before the vhost that references it goes live.
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

    /**
     * Refuse a web root that is not there yet (junior re-test #8, 2026-10-05).
     *
     * This used to create it — empty — and point the live site at it, so a
     * typo (`/etc` became `public_html/etc`) took the site down with a 403 and
     * no word of warning. An empty folder is never what someone moving a live
     * site's web root meant: the files they want served are either already
     * there or about to be uploaded, and the second case is a two-step job
     * the user can see. The full path is in the message, so `/etc` reads as
     * the site-relative path it really is.
     *
     * Live sites only: a pending site has nothing on disk yet, which is what
     * apply() already handles above by storing the value alone.
     */
    private function refuseMissing(Application $application, string $documentRoot, string $previous): void
    {
        $exists = $this->serverOps->probe(['test', '-d', $documentRoot], $this->context($application, 'web_root_exists'));

        if ($exists->ok) {
            return;
        }

        $application->web_root = $previous;

        // Anything but "not there" (a refused sudo, a timeout) is a fault, not
        // an answer, and must not read as one.
        // `denied` first: sudo refusing the command also exits 1.
        if ($exists->denied || $exists->exitCode() !== 1) {
            throw new WebRootOperationException($exists->reference);
        }

        throw ValidationException::withMessages([
            'web_root' => [__('validation.web_root_missing', ['path' => $documentRoot])],
        ]);
    }

    /**
     * Refuse a web root reached through a symlink (WR-01, old QA list).
     *
     * The site user owns public_html and can plant a link anywhere in it.
     * Served through one, the site publishes whatever the link points at —
     * /etc, another site's files — to the web. Every component from
     * public_html down is checked, not only the last: `a/b` is just as far
     * from home when `a` is the link.
     */
    private function refuseLinks(Application $application, string $documentRoot, string $previous): void
    {
        $base = rtrim($application->publicHtmlPath(), '/');
        $paths = [$base];

        foreach (array_filter(explode('/', substr($documentRoot, strlen($base))), fn (string $part) => $part !== '') as $part) {
            $paths[] = end($paths).'/'.$part;
        }

        foreach ($paths as $path) {
            // Exit 0 = it is a link; 1 = it is not (or is not there, which
            // refuseMissing() answers next).
            $link = $this->serverOps->probe(['test', '-L', $path], $this->context($application, 'web_root_link'));

            if ($link->ok) {
                $application->web_root = $previous;

                throw ValidationException::withMessages([
                    'web_root' => [__('validation.web_root_symlink', ['path' => $path])],
                ]);
            }

            if ($link->denied || $link->exitCode() !== 1) {
                $application->web_root = $previous;

                throw new WebRootOperationException($link->reference);
            }
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
