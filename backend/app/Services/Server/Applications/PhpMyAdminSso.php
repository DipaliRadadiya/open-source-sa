<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\View;

/**
 * One-click sign-in to a phpMyAdmin site.
 *
 * How the token reaches the site, and why it is a file, is shared with Adminer
 * and explained on {@see DatabaseClientSso}. What is phpMyAdmin's own: the
 * signon server entry in `config.inc.php` and the session layout phpMyAdmin
 * reads (`PMA_single_signon_*`).
 */
class PhpMyAdminSso extends DatabaseClientSso
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

    public function configPath(Application $application): string
    {
        return $this->documentRoot($application).'/config.inc.php';
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
        $result = $this->ensureTokenDirectory($application);

        if ($result->failed()) {
            return $result;
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
        $read = $this->run($this->asUser($application, ['cat', $path]), $application);

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
     */
    public function issue(
        Application $application,
        string $username,
        string $password,
        ?string $database,
    ): ?string {
        return $this->issueToken($application, [
            'username' => $username,
            'password' => $password,
            'database' => $database,
        ]);
    }

    protected function opName(): string
    {
        return 'phpmyadmin.sso';
    }
}
