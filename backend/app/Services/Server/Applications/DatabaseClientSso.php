<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Services\Server\Php\RuntimeOwnership;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Str;

/**
 * One-click sign-in to a browser database client the panel installed —
 * phpMyAdmin ({@see PhpMyAdminSso}) or Adminer ({@see AdminerSso}).
 *
 * The panel knows a database user's credentials; the client only trusts a PHP
 * session written on its own site. Nothing in the panel can write that session
 * — it is a different application, running as a different account. So the two
 * meet on disk: the panel drops a file that only the client site's user can
 * read, and sends the browser to a small script on that site holding the file's
 * name. The script reads it, deletes it, writes the session, and redirects.
 *
 * Why not the obvious alternatives:
 *  - **Credentials in the URL.** They land in the browser history, the access
 *    log of every proxy in between, and the `Referer` of the first outbound
 *    link the client renders.
 *  - **A shared cache.** The panel's Redis is the panel's; reaching it from a
 *    file in a customer's web root means putting the panel's Redis credentials
 *    in a customer's web root.
 *  - **A callback to the panel API.** Works, but makes a database login depend
 *    on the panel being reachable from the site, on the panel's own
 *    certificate, and on whichever of those two is misconfigured today.
 *
 * The file is the whole trust boundary, which is why {@see canIssue()} refuses
 * to mint one for a site whose PHP runs under the shared, server-wide pool: in
 * that arrangement every site on the box executes as the same account, and a
 * file readable by the client is readable by all of them.
 *
 * Extracted from PhpMyAdminSso when Adminer arrived, so the two cannot drift
 * apart on the parts that make a token private: where it lives, who writes it,
 * how long it lasts and what sweeps it.
 */
abstract class DatabaseClientSso
{
    /**
     * Long enough to survive a redirect, short enough that a token left in a
     * browser's history is worthless by the time anyone reads it.
     */
    public const TOKEN_TTL_SECONDS = 60;

    public function __construct(
        protected ServerOps $serverOps,
        protected RuntimeOwnership $ownership,
    ) {}

    /** The `op` the server-ops log files this client's commands under. */
    abstract protected function opName(): string;

    /**
     * Where token files are dropped: under the site's `.panel` directory, which
     * is above the document root by design. A token holding a database password
     * must not be somewhere a visitor could request by guessing its name.
     */
    public function tokenDirectory(Application $application): string
    {
        return $application->panelPath().'/sso';
    }

    public function shimPath(Application $application): string
    {
        return $this->documentRoot($application).'/sso.php';
    }

    /**
     * Whether this site can be signed into at all.
     *
     * PHP-FPM gives an isolated site its own pool running as its own user; the
     * shared pool runs every site on the server as one account. The token file
     * is only private in the first case, so the second is refused rather than
     * quietly downgraded — a credential readable by every other site on the box
     * is exactly what per-site pools exist to prevent.
     */
    public function canIssue(Application $application): bool
    {
        // Asked of RuntimeOwnership rather than of `isolated_at`, which
        // records whether a site has an FPM *pool*. On OpenLiteSpeed that
        // column is null for every site forever, so this refused every
        // database on the server with `phpmyadmin_not_isolated` — a whole
        // feature dead on one stack, reported as one disabled button.
        return $this->ownership->runsAsOwnUser($application);
    }

    /**
     * Make the private token directory, the way every other user directory in
     * `.panel` is made (PanelDirectory), then 0700 by its owner: it holds
     * database passwords for the next sixty seconds each.
     */
    protected function ensureTokenDirectory(Application $application): ServerOpsResult
    {
        $directory = $this->tokenDirectory($application);

        app(PanelDirectory::class)->ensure($application, basename($directory));

        return $this->run($this->asUser($application, ['chmod', '0700', $directory]), $application);
    }

    /**
     * Drop a one-time token file holding $payload and return the URL that
     * redeems it, or null when the file could not be written.
     *
     * The scheme comes from the application rather than being assumed: a site
     * with no certificate rejects requests on 443, and sending someone
     * there produces a connection refused that reads as a broken panel.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function issueToken(Application $application, array $payload): ?string
    {
        $token = Str::random(64);

        $payload['expires_at'] = now()->addSeconds(self::TOKEN_TTL_SECONDS)->getTimestamp();

        $result = $this->writeFile(
            $application,
            $this->tokenDirectory($application)."/{$token}.json",
            json_encode($payload, JSON_THROW_ON_ERROR),
            '0600',
        );

        if ($result->failed()) {
            return null;
        }

        return $application->url("/sso.php?token={$token}");
    }

    /**
     * Remove token files left behind by a link nobody clicked.
     *
     * They expire on read, so a stale one cannot be redeemed — but it still
     * holds a password, and an unswept directory accumulates one per click
     * forever.
     */
    public function sweep(Application $application): void
    {
        $this->serverOps->run([
            'find', $this->tokenDirectory($application),
            '-maxdepth', '1', '-name', '*.json',
            '-mmin', '+1', '-delete',
        ], ['feature' => 'database', 'op' => $this->opName().'.sweep', 'application' => $application->id]);
    }

    /**
     * The site's served directory.
     *
     * Resolved on call rather than injected. `ApplicationProvisioner` pulls in
     * most of this namespace, and a constructor dependency on it from a service
     * that namespace might one day reach back into closes a container cycle —
     * which does not raise an error, it hangs the process until it is killed.
     */
    protected function documentRoot(Application $application): string
    {
        return app(ApplicationProvisioner::class)->documentRoot($application);
    }

    /**
     * Contents over stdin, never as an argument: a token file holds a database
     * password, and an argument is visible in `ps` to every account on the box.
     */
    protected function writeFile(Application $application, string $path, string $contents, string $mode): ServerOpsResult
    {
        // As the site user, all of it. Every path here is in a directory that
        // user owns — the document root, and `.panel/sso` — and this ran as
        // root: `tee` followed a link the user had put at `sso.php` or
        // `config.inc.php`, and the `chown` after it then handed whatever the
        // link pointed at to the user. Found with the same class of bug in
        // `.panel` (2026-09-29). Written as the user the file is theirs by
        // construction, and a link reaches only what they could already
        // write — so there is nothing left to chown.
        $result = $this->run($this->asUser($application, ['tee', $path]), $application, input: $contents);

        if ($result->failed()) {
            return $result;
        }

        return $this->run($this->asUser($application, ['chmod', $mode, $path]), $application);
    }

    /**
     * @param  array<int, string>  $command
     * @return array<int, string>
     */
    protected function asUser(Application $application, array $command): array
    {
        return ['runuser', '-u', $application->systemUser->username, '--', ...$command];
    }

    /**
     * @param  array<int, string>  $command
     */
    protected function run(array $command, Application $application, ?string $input = null): ServerOpsResult
    {
        return $this->serverOps->run($command, [
            'feature' => 'database',
            'op' => $this->opName(),
            'application' => $application->id,
        ], input: $input);
    }
}
