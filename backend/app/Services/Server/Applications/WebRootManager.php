<?php

namespace App\Services\Server\Applications;

use App\Enums\ApplicationStatus;
use App\Exceptions\Server\Application\WebRootOperationException;
use App\Exceptions\Server\WebServer\WebServerReloadFailedException;
use App\Models\Application;
use App\Services\Server\Php\PoolManager;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Validation\ValidationException;

/**
 * Move which directory of a site is actually served.
 *
 * The document root is not just the vhost's `root`. Three other things derive from it and break
 * quietly if only the vhost moves:
 *
 *  - the PHP-FPM pool's `session.save_path` and error log,
 *  - the systemd unit's `WorkingDirectory` for a Node app,
 *  - `.panel/.htpasswd`, which Password Protection points the vhost at.
 *
 * Ordering is the safety property: everything the new config references is put in place first,
 * the vhost is applied → tested → reloaded (rolled back on a failed test), and only then is the
 * old sidecar removed. A live config never references a file that is not there.
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

        // Not live: nothing is on the server to move, so storing the value is the whole change.
        // A disabled site's vhost points at the disabled page; re-applying the real one here
        // would put the site back online as a side effect.
        if ($application->status !== ApplicationStatus::Active || $application->disabled_at !== null) {
            $application->web_root = $next;
            $application->save();

            return;
        }

        $application->web_root = $next;

        $documentRoot = $this->provisioner->documentRoot($application);

        $this->refuseLinks($application, $documentRoot, $previous);
        $this->refuseMissing($application, $documentRoot, $previous);

        // Never `mkdir`/`chown` here: they would run as root inside the site user's public_html
        // and follow a planted symlink (public_html/pub -> /etc), handing the target to the user.

        // The credential file lives above the document root; this only guarantees it exists
        // before the vhost that references it goes live.
        $this->basicAuth->publish($application);

        // Pool before vhost: the vhost starts sending requests into the pool, so it changes last.
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

        $reload = $this->webServers->driver()->reload();

        // Saved even if the reload failed: the tested config is on disk and the row must match.
        // The failure is still reported (WebServerReloadFailedException).
        $application->save();

        // After the reload: the unit's working directory only matters at the next start.
        $this->republishUnit($application, $documentRoot);

        if ($reload->failed()) {
            throw new WebServerReloadFailedException($reload->reference);
        }
    }

    /**
     * Put the previous web root back on the model and in the live config.
     *
     * Nothing was reloaded yet, so the server still serves the old root; this restores the files
     * to match. Never `rm -rf` a path derived from user input to roll back.
     */
    private function rollback(Application $application, string $previous): void
    {
        $application->web_root = $previous;

        $this->republishPool($application);
        $this->applyVhost($application);
        $this->basicAuth->publish($application);
    }

    /**
     * Refuse a web root that is not there yet.
     *
     * Never create it: a typo (`/etc` became `public_html/etc`) would point the live site at an
     * empty folder and take it down with a 403. The message carries the full path.
     * Live sites only; apply() stores the value alone for the others.
     */
    private function refuseMissing(Application $application, string $documentRoot, string $previous): void
    {
        $exists = $this->serverOps->probe(['test', '-d', $documentRoot], $this->context($application, 'web_root_exists'));

        if ($exists->ok) {
            return;
        }

        $application->web_root = $previous;

        // Anything but "not there" (refused sudo, timeout) is a fault, not an answer.
        // `denied` first: sudo refusing the command also exits 1.
        if ($exists->denied || $exists->exitCode() !== 1) {
            throw new WebRootOperationException($exists->reference);
        }

        throw ValidationException::withMessages([
            'web_root' => [__('validation.web_root_missing', ['path' => $documentRoot])],
        ]);
    }

    /**
     * Refuse a web root reached through a symlink.
     *
     * The site user can plant a link anywhere in public_html and would publish its target
     * (/etc, another site's files). Every component from public_html down is checked, not
     * only the last: `a/b` escapes just as far when `a` is the link.
     */
    private function refuseLinks(Application $application, string $documentRoot, string $previous): void
    {
        $base = rtrim($application->publicHtmlPath(), '/');
        $paths = [$base];

        foreach (array_filter(explode('/', substr($documentRoot, strlen($base))), fn (string $part) => $part !== '') as $part) {
            $paths[] = end($paths).'/'.$part;
        }

        foreach ($paths as $path) {
            // Exit 0 = a link; 1 = not a link (or missing, which refuseMissing() handles).
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
     * Only an isolated site has a pool of its own to rewrite; the shared pool does not move.
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
     * A Node app's unit runs from the document root. Rewritten without starting anything: a
     * stopped app must not be started by a settings change.
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
     * Stored without leading/trailing slash, so `/public`, `public/` and `public` are equal.
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
