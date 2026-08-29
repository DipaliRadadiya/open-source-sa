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
        //
        // **The names matter, and getting them wrong installed nothing.** The
        // previous set — `admin__username`, `admin__password`, `admin__email` —
        // was a guess at a double-underscore-to-colon convention NodeBB does not
        // apply here. `checkSetupFlagEnv()` in `src/install.js` does not walk
        // `process.env` looking for a separator; it tests a **hardcoded map**
        // (`NODEBB_ADMIN_USERNAME` → `admin:username`, and so on) and ignores
        // every name outside it. So all four answers were invisible, the
        // required-values check failed, and NodeBB called a bare
        // `process.exit()` — which is **exit code 0**. The panel read success
        // and carried on, leaving a forum with no `config` document, therefore
        // no `theme:id`, therefore no theme templates, therefore
        // "Failed to lookup view!" on every request.
        //
        // These names are read. `admin:password:confirm` is not passed because
        // NodeBB derives it itself (`setupVal['admin:password:confirm'] =
        // setupVal['admin:password']`), and there is no env name mapped to it —
        // an unmapped `NODEBB_*` var lands on `setupVal[undefined]`.
        //
        // Upstream also accepts the whole answer set as positional JSON, which
        // is the other documented path. It is deliberately not used: the
        // argument holds the admin password, and anything on a command line is
        // readable in `/proc/<pid>/cmdline` for as long as the command runs.
        // That is the exact exposure `runWithSecretEnv` exists to avoid.
        $this->runWithSecretEnv('install_app', $application, ['./nodebb', 'setup'], $documentRoot, [
            'NODEBB_ADMIN_USERNAME' => (string) ($settings['admin_username'] ?? 'admin'),
            'NODEBB_ADMIN_EMAIL' => (string) ($settings['admin_email'] ?? ''),
            'NODEBB_ADMIN_PASSWORD' => (string) ($settings['admin_password'] ?? ''),
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
                // Every app this panel provisions sits behind its nginx, and
                // without this nobody can log in. `webserver.js` reads
                // `trust_proxy` and **defaults it to `false`**, so Express sees
                // `req.secure === false` on an https request that arrived over
                // the proxy; express-session then refuses to set a `secure`
                // session cookie, no session is established, and the login POST
                // comes back `?error=csrf-invalid`. The forum renders fine,
                // which is what makes it look like a login bug rather than a
                // proxy one.
                //
                // Upstream ships `src/upgrades/4.14.3/trust_proxy.js` to
                // backfill exactly this value, but an upgrade run against a
                // database setup never populated marks every migration skipped,
                // so the backfill did not happen either.
                //
                // Written here rather than in `config()` because `setup`
                // rewrites `config.json` from its own answers; this patch runs
                // after it and is the only place the value survives.
                //
                // `1`, not `true`, and the difference is a spoofable client IP.
                // Upstream's own backfill writes `true`, and its startup warning
                // says to enable this "only when NodeBB is behind a reverse
                // proxy that **strips or overwrites** X-Forwarded-For" — this
                // panel's nginx does neither. `node.blade.php` sends
                // `X-Forwarded-For $proxy_add_x_forwarded_for`, which *appends*
                // the real peer to whatever the client already sent. Under
                // `true` Express trusts every hop and takes the **leftmost**
                // entry, so a request carrying its own `X-Forwarded-For: 1.2.3.4`
                // decides its own `req.ip` — and NodeBB rate limiting, IP bans
                // and moderation logs all read that.
                //
                // `1` trusts exactly one hop, so Express takes the entry nginx
                // itself appended: the real client, and not forgeable from
                // outside. `req.secure` is satisfied either way — it reads
                // `X-Forwarded-Proto`, which the vhost sets rather than appends.
                $current['trust_proxy'] = 1;

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
        //
        // Reporting it was not enough, and this is the third pass. On a 2 GB
        // box the build was still failing, and the report said nothing,
        // because the process was not failing — it was being **killed**. V8
        // sizes its heap from a compiled-in default rather than from the
        // machine, grows past what the box has, and the OOM killer takes it:
        // SIGKILL, no stderr, exit 137, a reference pointing at an empty log.
        //
        // `BuildMemoryBudget` caps the heap to a share of what is actually
        // available, which is upstream's own advice for small hosts. Under a
        // cap V8 collects rather than allocates, so the build usually now
        // finishes; and when it truly does not fit it says
        // `JavaScript heap out of memory` and exits non-zero *with output*,
        // instead of disappearing.
        $build = $this->runWithNode(
            'build',
            $application,
            ['./nodebb', 'build'],
            $documentRoot,
            app(BuildMemoryBudget::class)->nodeOptions(),
        );

        // A non-zero exit has already thrown by this point — `run()` does it
        // for every step, and classifies an OOM kill while it is there.
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
     * **Counting `.tpl` files was not enough, and this is the third pass.** A
     * forum whose `setup` never ran still passed this check: the core templates
     * ship with the clone and compile without a database, so 266 `.tpl` files
     * existed and "is there one?" answered yes. What was missing was everything
     * the *theme* provides — with no `config` document there is no `theme:id`,
     * so the theme's templates are never merged into the build.
     *
     * `header.tpl` and `footer.tpl` come from the theme and every single page
     * render needs both, which makes them the honest test: their absence is
     * precisely the state that produces "Failed to lookup view!". Asserting
     * them also covers the setup failure upstream of it, because a database
     * that setup never populated cannot yield a theme.
     *
     * @throws ProvisioningFailedException
     */
    private function assertAssetsBuilt(Application $application, string $documentRoot, ServerOpsResult $build): void
    {
        // Both names in one pass. No `-quit` here — unlike the old check this
        // is not asking "is there any file?" but "are these two present?", and
        // stopping at the first hit would accept a build that produced only one
        // of them.
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
