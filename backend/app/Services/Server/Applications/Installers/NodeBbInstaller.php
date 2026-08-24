<?php

namespace App\Services\Server\Applications\Installers;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use Illuminate\Support\Str;

/**
 * NodeBB — forum software.
 *
 * The only one of the Node applications that needs a database, and the only
 * one in the whole catalog that **cannot use MySQL**. NodeBB speaks MongoDB,
 * Redis or PostgreSQL; handing it a MySQL database would fail inside its own
 * setup with a message about a missing driver, which tells the user nothing
 * about what went wrong. `acceptedEngines()` is what stops that: without a
 * MongoDB on the server the install fails up front, naming the reason.
 *
 * Setup is the fragile part, and it is worth being honest about why.
 * `./nodebb setup` is interactive, and upstream has an open issue asking for a
 * config-file path (NodeBB/NodeBB#12958) — there isn't one. What does work,
 * and is what their own cloud tooling uses, is passing the answers as
 * environment variables. Two consequences follow:
 *
 *  - the variables carry the database and admin passwords, so they go through
 *    a 0600 file rather than the command line;
 *  - `nodebb setup` rewrites `config.json`, so the file is written **after**
 *    setup as well as before — writing it only once loses whatever the panel
 *    put there.
 *
 * This is the least-verified installer in the catalog. It follows upstream's
 * documented environment-variable path, but that path has no test upstream
 * either, and it is the first thing to check on real hardware.
 */
class NodeBbInstaller extends AbstractNodeInstaller
{
    public function siteType(): string
    {
        return 'nodebb';
    }

    public function needsDatabase(): bool
    {
        return true;
    }

    /**
     * MongoDB only, of the engines this panel manages. Redis and PostgreSQL
     * would also work for NodeBB; neither is something the panel creates
     * databases and users in today.
     */
    public function acceptedEngines(): array
    {
        return ['mongodb'];
    }

    /**
     * `--no-daemon` because the unit is `Type=simple`: without it the loader
     * forks and exits, systemd sees the parent die and kills the children it
     * has left in the cgroup. `--no-silent` sends the logs to the journal
     * instead of a file nobody looks at.
     */
    public function startCommand(Application $application, string $documentRoot): ?string
    {
        return "node {$documentRoot}/loader.js --no-daemon --no-silent";
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function install(Application $application, string $documentRoot, array $context): void
    {
        $settings = $application->settings ?? [];

        $this->cloneInto(
            $application,
            (string) config('server.installers.nodebb.repository'),
            (string) config('server.installers.nodebb.branch'),
            $documentRoot,
        );

        $config = $this->config($application, $documentRoot, $context);

        $this->writeSecretFile($application, "{$documentRoot}/config.json", $config);

        // `nodebb setup` installs the dependencies itself before running, so
        // there is no separate npm step. The answers travel in a 0600 file —
        // the admin password and the database password are both in here.
        $this->runWithSecretEnv('install_app', $application, ['./nodebb', 'setup'], $documentRoot, [
            'admin__username' => (string) ($settings['admin_username'] ?? 'admin'),
            'admin__email' => (string) ($settings['admin_email'] ?? ''),
            'admin__password' => (string) ($settings['admin_password'] ?? ''),
            'admin__password__confirm' => (string) ($settings['admin_password'] ?? ''),
        ]);

        // Setup rewrote config.json. Put ours back, or the site loses the URL
        // and port the panel gave it and starts answering on 4567.
        $this->writeSecretFile($application, "{$documentRoot}/config.json", $config);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function config(Application $application, string $documentRoot, array $context): string
    {
        return json_encode([
            // NodeBB builds every absolute link from this, so a wrong value is
            // a forum whose links all point somewhere else.
            'url' => $application->url(),
            'secret' => Str::random(40),
            'database' => 'mongo',
            'port' => (int) ($application->app_port ?: 4567),
            // Reached through the reverse proxy only.
            'bind_address' => '127.0.0.1',
            'mongo' => [
                'host' => (string) ($context['db_host'] ?? '127.0.0.1'),
                'port' => (string) ($context['db_port'] ?? 27017),
                'username' => (string) ($context['db_user'] ?? ''),
                'password' => (string) ($context['db_password'] ?? ''),
                'database' => (string) ($context['database'] ?? ''),
                'uri' => '',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    public function syncUrl(Application $application, string $url): void
    {
        $path = $application->documentRoot().'/config.json';

        $changed = $this->configMutator->transform($application, $path, function (string $contents) use ($url): string {
            $config = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($config)) {
                throw new \RuntimeException('NodeBB config is not an object.');
            }

            $config['url'] = $url;

            return json_encode(
                $config,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            )."\n";
        });

        if (! $changed) {
            return;
        }

        $result = $this->supervisor->restart($application);

        if ($result->failed()) {
            throw new ProvisioningFailedException('sync_url', $result->reference);
        }
    }
}
