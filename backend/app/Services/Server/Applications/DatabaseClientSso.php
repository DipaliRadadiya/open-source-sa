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
 * The client only trusts a PHP session written on its own site, which the panel cannot write.
 * So the panel drops a token file only the client site's user can read and sends the browser
 * to a small script on that site holding the file's name. The script reads it, deletes it,
 * writes the session, and redirects.
 *
 * Not done on purpose:
 *  - Credentials in the URL: they land in browser history, proxy logs and `Referer`.
 *  - A shared cache: it would put the panel's Redis credentials in a customer's web root.
 *  - A callback to the panel API: ties a database login to the panel being reachable.
 *
 * The file is the whole trust boundary, so {@see canIssue()} refuses a site whose PHP runs
 * under the shared pool: every site would run as the same account and could read it.
 *
 * Shared by both clients so where a token lives, who writes it, how long it lasts and what
 * sweeps it cannot drift apart.
 */
abstract class DatabaseClientSso
{
    /** Long enough to survive a redirect, short enough that a token left in history is dead. */
    public const TOKEN_TTL_SECONDS = 60;

    public function __construct(
        protected ServerOps $serverOps,
        protected RuntimeOwnership $ownership,
    ) {}

    /** The `op` the server-ops log files this client's commands under. */
    abstract protected function opName(): string;

    /**
     * Where token files are dropped: under `.panel`, above the document root, so a visitor
     * cannot request one by guessing its name.
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
     * The token file is only private when the site runs as its own user; the shared pool is
     * refused rather than quietly downgraded.
     */
    public function canIssue(Application $application): bool
    {
        // Not `isolated_at`: that only says whether a site has an FPM pool, and is always null
        // on OpenLiteSpeed.
        return $this->ownership->runsAsOwnUser($application);
    }

    /**
     * Make the token directory like every other user directory in `.panel`
     * (PanelDirectory), then 0700: it holds database passwords.
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
     * The scheme comes from the application: a site with no certificate refuses 443.
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
     * They expire on read, but a stale file still holds a password and would pile up.
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
     * Resolved on call, not injected: a constructor dependency on `ApplicationProvisioner`
     * can close a container cycle, which hangs the process instead of raising.
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
        // Always as the site user. As root, `tee` would follow a symlink the user planted at
        // `sso.php` or `config.inc.php`, and a `chown` would then hand its target to them.
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
