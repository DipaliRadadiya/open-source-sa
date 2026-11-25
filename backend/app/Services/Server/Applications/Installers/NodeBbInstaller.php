<?php

namespace App\Services\Server\Applications\Installers;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Server\Applications\BuildMemoryBudget;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Str;

/**
 * NodeBB — forum software.
 *
 * The only Node application that needs a database, and it **cannot use MySQL**: it speaks
 * MongoDB, Redis or PostgreSQL. `acceptedEngines()` makes the install fail up front, naming
 * the reason, instead of failing inside NodeBB's own setup.
 *
 * `./nodebb setup` is interactive and has no config-file path upstream (NodeBB/NodeBB#12958),
 * so the answers go in as environment variables. Two consequences:
 *
 *  - the variables carry the database and admin passwords, so they go through a 0600 file,
 *    not the command line;
 *  - `nodebb setup` rewrites `config.json`, so the file is written **after** setup as well as before.
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
     * MongoDB and PostgreSQL, in that order — the first available engine wins, so MongoDB stays
     * the default on a server that has both.
     *
     * Not a label: `config()` writes the driver name *and* its settings block, so an engine
     * listed here needs a matching block (see `postgresConfig()`). Redis would also work but is
     * absent: the panel does not create Redis databases or users.
     */
    public function acceptedEngines(): array
    {
        return ['mongodb', 'postgresql'];
    }

    /**
     * `--no-daemon` because the unit is `Type=simple`: otherwise the loader forks and exits and
     * systemd kills the children it left behind. `--no-silent` sends logs to the journal.
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
        $settings = $application->installSettings();

        $this->cloneInto(
            $application,
            (string) config('server.installers.nodebb.repository'),
            (string) config('server.installers.nodebb.branch'),
            $documentRoot,
        );

        $config = $this->config($application, $documentRoot, $context);

        $this->writeSecretFile($application, "{$documentRoot}/config.json", $config);

        // `nodebb setup` installs the dependencies itself. The answers travel in a 0600 file (they
        // include the admin and database passwords).
        //
        // **The names matter:** `checkSetupFlagEnv()` in NodeBB's `src/install.js` tests a hardcoded
        // map (`NODEBB_ADMIN_USERNAME` → `admin:username`, ...) and ignores every other name. With
        // wrong names setup fails its required-values check but exits 0, leaving a forum with no
        // `config` document ("Failed to lookup view!" on every request).
        // `admin:password:confirm` is not passed: NodeBB derives it, and no env name maps to it.
        //
        // Upstream also accepts the answers as positional JSON, deliberately not used: a command
        // line is readable in `/proc/<pid>/cmdline` (the exposure `runWithSecretEnv` avoids).
        $this->runWithSecretEnv('install_app', $application, ['./nodebb', 'setup'], $documentRoot, [
            'NODEBB_ADMIN_USERNAME' => (string) ($settings['admin_username'] ?? 'admin'),
            'NODEBB_ADMIN_EMAIL' => (string) ($settings['admin_email'] ?? ''),
            'NODEBB_ADMIN_PASSWORD' => (string) ($settings['admin_password'] ?? ''),
        ]);

        // Setup rewrote config.json; the panel's URL and port must survive it or the site answers on
        // 4567. **Patched, not replaced:** overwriting it discards whatever `setup` added, and the
        // next step (`./nodebb build`) reads this file to reach the database and find the theme.
        $this->configMutator->transform(
            $application,
            "{$documentRoot}/config.json",
            function (string $contents) use ($application): string {
                $current = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

                if (! is_array($current)) {
                    throw new \RuntimeException('NodeBB config is not an object.');
                }

                $current['url'] = $application->url();
                $current['port'] = (int) ($application->app_port ?: 4567);
                // Reached through the reverse proxy only.
                $current['bind_address'] = '127.0.0.1';
                // Without `trust_proxy` nobody can log in behind the panel's nginx: it defaults to
                // `false`, so Express sees `req.secure === false` on https requests, no `secure`
                // session cookie is set, and login fails with `?error=csrf-invalid`.
                // Written here, not in `config()`, because `setup` rewrites config.json.
                //
                // `1`, not `true`: the panel's nginx *appends* to `X-Forwarded-For`, and `true` makes
                // Express take the leftmost (client-supplied) entry, so a client could forge `req.ip`
                // (rate limits, IP bans, moderation logs). `1` trusts exactly one hop, the entry nginx
                // appended.
                $current['trust_proxy'] = 1;

                return json_encode(
                    $current,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                )."\n";
            },
        );

        // `setup` builds assets itself, but that build can fail **without failing setup**, leaving a
        // forum that answers "Failed to lookup view!" everywhere. As its own step, `runWithNode`
        // throws on a non-zero exit, so a failed build fails provisioning with its output attached.
        //
        // A common cause is memory: V8 sizes its heap from a compiled-in default, grows past a small
        // box, and the OOM killer sends SIGKILL (exit 137, no stderr). `BuildMemoryBudget` caps the
        // heap to what is available, so a build that does not fit reports
        // `JavaScript heap out of memory` with output instead of vanishing.
        $build = $this->runWithNode(
            'build',
            $application,
            ['./nodebb', 'build'],
            $documentRoot,
            app(BuildMemoryBudget::class)->nodeOptions(),
        );

        // A non-zero exit has already thrown by this point (`run()` does it for every step).
        $this->assertAssetsBuilt($application, $documentRoot, $build);
    }

    /**
     * Prove the build produced templates, rather than believing its exit code.
     *
     * `./nodebb build` can exit 0 having compiled nothing. `build/public/templates` is where
     * Express looks, so `header.tpl` and `footer.tpl` must be there: both come from the *theme*
     * (every page render needs them), and a database that `setup` never populated yields no
     * theme. Counting any `.tpl` file is not enough: the core templates ship with the clone.
     *
     * @throws ProvisioningFailedException
     */
    private function assertAssetsBuilt(Application $application, string $documentRoot, ServerOpsResult $build): void
    {
        // Both names in one pass, no `-quit`: stopping at the first hit would accept a build that
        // produced only one of them.
        $probe = $this->serverOps->run(
            [
                'runuser', '-u', $application->systemUser->username, '--',
                'find', "{$documentRoot}/build/public/templates",
                '(', '-name', 'header.tpl', '-o', '-name', 'footer.tpl', ')', '-print',
            ],
            [
                'feature' => 'application',
                'op' => 'installer.build_check',
                'application' => $application->id,
                'log_output' => true,
            ],
            timeout: 60,
        );

        $found = $probe->failed() ? '' : $probe->output();

        if (str_contains($found, 'header.tpl') && str_contains($found, 'footer.tpl')) {
            return;
        }

        // The *build's* reference, not the probe's: the build's log entry holds the output that says why.
        throw new ProvisioningFailedException('build', $build->reference);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function config(Application $application, string $documentRoot, array $context): string
    {
        // NodeBB loads its driver as `require('./' + nconf.get('database'))` from `src/database/`,
        // so this is a filename, not a label: `postgres`, never `postgresql`.
        $engine = (string) ($context['engine'] ?? 'mongodb');
        $driver = $engine === 'postgresql' ? 'postgres' : 'mongo';

        return json_encode([
            // NodeBB builds every absolute link from this.
            'url' => $application->url(),
            'secret' => Str::random(40),
            'database' => $driver,
            'port' => (int) ($application->app_port ?: 4567),
            // Reached through the reverse proxy only.
            'bind_address' => '127.0.0.1',
            $driver => $driver === 'postgres'
                ? $this->postgresConfig($context)
                : $this->mongoConfig($context),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function mongoConfig(array $context): array
    {
        return [
            'host' => (string) ($context['db_host'] ?? '127.0.0.1'),
            'port' => (string) ($context['db_port'] ?? 27017),
            'username' => (string) ($context['db_user'] ?? ''),
            'password' => (string) ($context['db_password'] ?? ''),
            'database' => (string) ($context['database'] ?? ''),
            'uri' => '',
        ];
    }

    /**
     * The keys NodeBB reads, taken from its source (`src/database/postgres.js`, `postgres/connection.js`):
     * `postgres:host|port|username|password|database|ssl`, with `username` mapped onto node-postgres's `user`.
     *
     * `port` is an int, unlike the mongo block's string (NodeBB's own type for this field). `ssl` is
     * an explicit false: the panel's PostgreSQL listens on loopback only.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function postgresConfig(array $context): array
    {
        return [
            'host' => (string) ($context['db_host'] ?? '127.0.0.1'),
            'port' => (int) ($context['db_port'] ?? 5432),
            'username' => (string) ($context['db_user'] ?? ''),
            'password' => (string) ($context['db_password'] ?? ''),
            'database' => (string) ($context['database'] ?? ''),
            'ssl' => false,
        ];
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
