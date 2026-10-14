<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\View;

/**
 * One-click sign-in to an Adminer site.
 *
 * How the token reaches the site, and why it is a file, is shared with
 * phpMyAdmin and explained on {@see DatabaseClientSso}. What is Adminer's own:
 *
 *  - **`index.php`**, a wrapper that locks Adminer to this server's own
 *    database servers. Stock Adminer takes any host and any driver on its login
 *    form, which on a public URL is a probe for whatever the server can reach.
 *  - **`sso.php`** writes the session layout Adminer's own login form writes
 *    (`pwds` / `db` keyed by driver, server and user).
 *
 * Both are rewritten on every click, like phpMyAdmin's script: a site
 * installed before a change to either repairs itself the next time someone
 * opens a database, and a database port changed since install is picked up.
 */
class AdminerSso extends DatabaseClientSso
{
    /**
     * The database servers an Adminer site may open, keyed by the name used in
     * its URLs: `?server=mysql`, `?pgsql=pgsql`. `server` is Adminer's driver
     * name for MySQL and MariaDB.
     *
     * @return array<string, array{driver: string, host: string, label: string}>
     */
    public function servers(): array
    {
        $host = (string) config('server.installers.adminer.db_host', '127.0.0.1');

        return [
            'mysql' => [
                'driver' => 'server',
                'host' => $host.':'.(int) config('server.databases.engines.mysql.default_port', 3306),
                'label' => 'MySQL / MariaDB',
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => $host.':'.(int) config('server.databases.engines.postgresql.default_port', 5432),
                'label' => 'PostgreSQL',
            ],
        ];
    }

    /**
     * The server key a database engine is opened under, or null for an engine
     * Adminer cannot open here. MongoDB is one: Adminer's driver for it needs
     * the `mongodb` PHP extension, which the panel does not install.
     */
    public function serverFor(string $engine): ?string
    {
        return match ((string) config("server.databases.engines.{$engine}.driver")) {
            'sql' => 'mysql',
            'pgsql' => 'pgsql',
            default => null,
        };
    }

    public function indexPath(Application $application): string
    {
        return $this->documentRoot($application).'/index.php';
    }

    public function renderIndex(): string
    {
        return View::make('server.apps.adminer.index', ['servers' => $this->servers()])->render();
    }

    public function renderShim(Application $application): string
    {
        return View::make('server.apps.adminer.sso', [
            'tokenDirectory' => $this->tokenDirectory($application),
            'drivers' => array_map(fn (array $server): string => $server['driver'], $this->servers()),
        ])->render();
    }

    /**
     * Put the token directory, the lock-down wrapper and the sign-in script in
     * place. Idempotent; see the class docblock for why it runs on every click.
     */
    public function installShim(Application $application): ServerOpsResult
    {
        $result = $this->ensureTokenDirectory($application);

        if ($result->failed()) {
            return $result;
        }

        $result = $this->writeFile($application, $this->indexPath($application), $this->renderIndex(), '0644');

        if ($result->failed()) {
            return $result;
        }

        return $this->writeFile($application, $this->shimPath($application), $this->renderShim($application), '0644');
    }

    /**
     * Drop a one-time token and return the URL that redeems it.
     */
    public function issue(
        Application $application,
        string $server,
        string $username,
        string $password,
        ?string $database,
    ): ?string {
        return $this->issueToken($application, [
            'server' => $server,
            'username' => $username,
            'password' => $password,
            'database' => $database,
        ]);
    }

    protected function opName(): string
    {
        return 'adminer.sso';
    }
}
