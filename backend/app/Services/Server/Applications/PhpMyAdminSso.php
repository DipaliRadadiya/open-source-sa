<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * One-click sign-in to a phpMyAdmin site.
 *
 * The panel knows a database user's credentials; phpMyAdmin only trusts a PHP
 * session written on its own site. Nothing in the panel can write that session
 * — it is a different application, running as a different account. So the two
 * meet on disk: the panel drops a file that only the phpMyAdmin site's user can
 * read, and sends the browser to a small script on that site holding the file's
 * name. The script reads it, deletes it, writes the session, and redirects.
 *
 * Why not the obvious alternatives:
 *  - **Credentials in the URL.** They land in the browser history, the access
 *    log of every proxy in between, and the `Referer` of the first outbound
 *    link phpMyAdmin renders.
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
 * file readable by phpMyAdmin is readable by all of them.
 */
class PhpMyAdminSso
{
    /**
     * Not 'phpMyAdmin': that is the session phpMyAdmin keeps for itself, and
     * upstream is explicit that the signon session has to be a different one.
     */
    public const SIGNON_SESSION = 'SVPanelSignon';

    /**
     * The second server entry in config.inc.php — same database host as the
     * first, reached only through this flow. The first stays on cookie auth so
     * that typing your own credentials never stops working.
     */
    public const SIGNON_SERVER = 2;

    /**
     * Long enough to survive a redirect, short enough that a token left in a
     * browser's history is worthless by the time anyone reads it.
     */
    public const TOKEN_TTL_SECONDS = 60;

    public function __construct(private ServerOps $serverOps) {}

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

    public function configPath(Application $application): string
    {
        return $this->documentRoot($application).'/config.inc.php';
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
        return $application->serving_profile !== 'php'
            || $application->isolated_at !== null;
    }

    /**
     * @return array{blowfishSecret: string, host: string, tempDir: string, signonSession: string, signonLabel: string}
     */
    public function configData(string $blowfishSecret, string $host, string $tempDir): array
    {
        return [
            'blowfishSecret' => $blowfishSecret,
            'host' => $host,
            'tempDir' => $tempDir,
            'signonSession' => self::SIGNON_SESSION,
            'signonLabel' => 'Control panel sign-in',
        ];
    }

    public function renderConfig(string $blowfishSecret, string $host, string $tempDir): string
    {
        return View::make(
            'server.apps.phpmyadmin.config',
            $this->configData($blowfishSecret, $host, $tempDir),
        )->render();
    }

    public function renderShim(Application $application): string
    {
        return View::make('server.apps.phpmyadmin.sso', [
            'tokenDirectory' => $this->tokenDirectory($application),
            'signonSession' => self::SIGNON_SESSION,
            'signonServer' => self::SIGNON_SERVER,
        ])->render();
    }

    /**
     * Put the sign-in script and its token directory in place.
     *
     * Rewritten every time rather than written once and checked for: it is two
     * commands, it is idempotent, and it means a site installed before this
     * feature existed — which is every phpMyAdmin site the panel has ever
     * created — repairs itself the first time someone clicks the button,
     * instead of needing to be deleted and made again.
     */
    public function installShim(Application $application): ServerOpsResult
    {
        $owner = $application->systemUser->username;
        $directory = $this->tokenDirectory($application);

        $steps = [
            ['mkdir', '-p', $directory],
            // 0700, and owned by the site: the directory holds database
            // passwords for the next sixty seconds each.
            ['chown', "{$owner}:{$owner}", $directory],
            ['chmod', '0700', $directory],
        ];

        foreach ($steps as $command) {
            $result = $this->run($command, $application);

            if ($result->failed()) {
                return $result;
            }
        }

        $result = $this->writeFile(
            $application,
            $this->shimPath($application),
            $this->renderShim($application),
            '0644',
        );

        if ($result->failed()) {
            return $result;
        }

        return $this->ensureSignonServer($application);
    }

    /**
     * Give an older site the second server entry the sign-in script needs.
     *
     * Every phpMyAdmin site created before this feature has a config.inc.php
     * with one server on cookie auth. Writing `sso.php` alone would leave the
     * script handing a session to a server that does not exist, so the config
     * has to be brought forward too.
     *
     * Left alone once it has the entry. Rewriting on every click would work,
     * but the file also holds `blowfish_secret`, and regenerating that logs out
     * everyone currently signed in — a fresh secret cannot decrypt the cookies
     * the old one issued.
     */
    private function ensureSignonServer(Application $application): ServerOpsResult
    {
        $path = $this->configPath($application);
        $read = $this->run(['cat', $path], $application);

        // A config that cannot be read is not a config that is missing the
        // entry. Rewriting on a failed read would replace a working file with
        // one built from guesses — the same shape of bug as treating an
        // unreadable /etc/fstab as an empty one.
        if ($read->failed()) {
            return $read;
        }

        $existing = $read->output();

        if (str_contains($existing, "'signon'")) {
            return $read;
        }

        // Reused when it is there so existing sessions survive; generated only
        // when the old file had none to find.
        preg_match("/sodium_hex2bin\('([0-9a-f]{64})'\)/", $existing, $matches);

        return $this->writeFile(
            $application,
            $path,
            $this->renderConfig(
                $matches[1] ?? bin2hex(random_bytes(32)),
                (string) config('server.installers.phpmyadmin.db_host', '127.0.0.1'),
                $application->rootPath().'/tmp',
            ),
            '0640',
        );
    }

    /**
     * Drop a one-time token and return the URL that redeems it.
     *
     * The scheme comes from the application rather than being assumed: a site
     * with no certificate does not answer on 443 at all, and sending someone
     * there produces a connection refused that reads as a broken panel.
     */
    public function issue(
        Application $application,
        string $username,
        string $password,
        ?string $database,
    ): ?string {
        $token = Str::random(64);

        $payload = json_encode([
            'username' => $username,
            'password' => $password,
            'database' => $database,
            'expires_at' => now()->addSeconds(self::TOKEN_TTL_SECONDS)->getTimestamp(),
        ], JSON_THROW_ON_ERROR);

        $result = $this->writeFile(
            $application,
            $this->tokenDirectory($application)."/{$token}.json",
            $payload,
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
        ], ['feature' => 'database', 'op' => 'phpmyadmin.sso.sweep', 'application' => $application->id]);
    }

    /**
     * The site's served directory.
     *
     * Resolved on call rather than injected. `ApplicationProvisioner` pulls in
     * most of this namespace, and a constructor dependency on it from a service
     * that namespace might one day reach back into closes a container cycle —
     * which does not raise an error, it hangs the process until it is killed.
     */
    private function documentRoot(Application $application): string
    {
        return app(ApplicationProvisioner::class)->documentRoot($application);
    }

    /**
     * Contents over stdin, never as an argument: a token file holds a database
     * password, and an argument is visible in `ps` to every account on the box.
     */
    private function writeFile(Application $application, string $path, string $contents, string $mode): ServerOpsResult
    {
        $owner = $application->systemUser->username;

        $result = $this->run(['tee', $path], $application, input: $contents);

        if ($result->failed()) {
            return $result;
        }

        // Mode before ownership, matching writeSecretFile(). `tee` creates the
        // file at the panel's umask — 0644, world-readable — so the narrowing
        // has to happen first; chowning a still-readable file only changes who
        // owns something everyone can already read.
        $result = $this->run(['chmod', $mode, $path], $application);

        if ($result->failed()) {
            return $result;
        }

        return $this->run(['chown', "{$owner}:{$owner}", $path], $application);
    }

    /**
     * @param  array<int, string>  $command
     */
    private function run(array $command, Application $application, ?string $input = null): ServerOpsResult
    {
        return $this->serverOps->run($command, [
            'feature' => 'database',
            'op' => 'phpmyadmin.sso',
            'application' => $application->id,
        ], input: $input);
    }
}
