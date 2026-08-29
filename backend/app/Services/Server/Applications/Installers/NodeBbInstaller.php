<?php

namespace App\Services\Server\Applications\Installers;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Server\ServerOpsResult;
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

        // Setup rewrote config.json, and the panel's URL and port have to
        // survive that or the site answers on 4567. **Patched, not replaced.**
        //
        // Overwriting it wholesale put back exactly the six keys this class
        // knows about and silently discarded anything `setup` had added for
        // itself. The next command to run is `./nodebb build`, which reads
        // this file to reach the database and find the active theme — and a
        // build that cannot do that compiles no templates *and still exits 0*,
        // which is the failure this installer has now produced twice.
        //
        // Whether that discarded key is the cause is unproven. Writing over a
        // file the application just wrote, to restore values that could be
        // edited in place, is indefensible either way — and the mutator that
        // does it correctly was already here, used by syncUrl() below.
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

                return json_encode(
                    $current,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                )."\n";
            },
        );

        // `setup` builds the assets itself, so this looks redundant — and that
        // reasoning is exactly what left it out. `setup`'s build can fail
        // **without failing setup**: the reported symptom was a forum that
        // installed cleanly, reported active, and answered every request with
        // "Internal server error. Failed to lookup view! Did you run
        // `./nodebb build`?" — Express finding no `.tpl` files under
        // build/public/templates because nothing had compiled them.
        //
        // Running it as its own step is therefore not a second build, it is
        // the only one whose result is checked: `runWithNode` throws on a
        // non-zero exit, so a failed build now fails provisioning with its
        // output attached instead of producing a site that 500s on every page.
        //
        // The usual reason it dies is memory — the build runs its targets in
        // parallel and will exhaust a small VPS — which is precisely the kind
        // of failure that must be reported rather than swallowed.
        $build = $this->runWithNode('build', $application, ['./nodebb', 'build'], $documentRoot);

        $this->assertAssetsBuilt($application, $documentRoot, $build);
    }

    /**
     * Prove the build produced templates, rather than believing its exit code.
     *
     * Checking the exit status was not enough, and this is the second pass at
     * the same bug: a forum created *after* the build step was added still
     * answered "Failed to lookup view! Did you run `./nodebb build`?" on every
     * request, with no error anywhere in provisioning. So `./nodebb build`
     * exited 0 having compiled nothing — which the NodeBB community reports
     * too ("it seems build is failing silently", a webpack run logging
     * `Module not found` and finishing anyway).
     *
     * `build/public/templates` is the directory Express is looking in when it
     * raises that error, so it is the thing worth asserting: no `.tpl` file
     * there means the forum is broken, whatever the build said about itself.
     *
     * The same detect-don't-trust rule the rest of the panel already follows —
     * `php-fpm -t` before a reload, `systemctl is-active` after a start. A
     * command's return value is a claim; the artifact is the evidence.
     *
     * @throws ProvisioningFailedException
     */
    private function assertAssetsBuilt(Application $application, string $documentRoot, ServerOpsResult $build): void
    {
        // `-quit` stops at the first hit: this asks "is there one?", and the
        // directory holds thousands of files on a healthy install.
        $probe = $this->serverOps->run(
            [
                'runuser', '-u', $application->systemUser->username, '--',
                'find', "{$documentRoot}/build/public/templates",
                '-name', '*.tpl', '-print', '-quit',
            ],
            [
                'feature' => 'application',
                'op' => 'installer.build_check',
                'application' => $application->id,
                'log_output' => true,
            ],
            timeout: 60,
        );

        if (! $probe->failed() && trim($probe->output()) !== '') {
            return;
        }

        // The *build's* reference, not the probe's. The probe found the
        // problem; the build caused it, and the build's log entry is the one
        // holding the output that says why — which the error log now surfaces.
        // Reporting the probe here would hand the user a reference whose
        // recorded output is an empty `find`.
        throw new ProvisioningFailedException('build', $build->reference);
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
