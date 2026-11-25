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
     * Not 'phpMyAdmin': the signon session must differ from phpMyAdmin's own.
     */
    public const SIGNON_SESSION = 'SVPanelSignon';

    /**
     * The second server entry in config.inc.php: same host as the first, reached only through
     * this flow. The first stays on cookie auth.
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
     * Rewritten every time: it is idempotent, and a site installed before this feature repairs
     * itself on the first click.
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
     * An older config.inc.php has one server on cookie auth; `sso.php` alone would hand a
     * session to a server that does not exist.
     *
     * Left alone once it has the entry: the file also holds `blowfish_secret`, and a fresh one
     * logs out everyone signed in.
     */
    private function ensureSignonServer(Application $application): ServerOpsResult
    {
        $path = $this->configPath($application);
        $read = $this->run($this->asUser($application, ['cat', $path]), $application);

        // An unreadable config is not a config missing the entry: rewriting on a failed read
        // would replace a working file with one built from guesses.
        if ($read->failed()) {
            return $read;
        }

        $existing = $read->output();

        if (str_contains($existing, "'signon'")) {
            return $read;
        }

        // Reused so existing sessions survive; generated only when the old file had none.
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
