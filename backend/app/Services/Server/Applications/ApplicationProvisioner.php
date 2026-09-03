<?php

namespace App\Services\Server\Applications;

use App\Actions\Server\Application\AutoIssueCertificate;
use App\Exceptions\Server\Application\ApplicationAvailabilityException;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Jobs\MeasureApplicationSize;
use App\Models\Application;
use App\Models\ApplicationPhpSettings;
use App\Services\Server\Php\PoolManager;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\WebServers\WebServerManager;

/**
 * Puts an application on the server: directory, ownership, site config, and a
 * tested reload.
 *
 * Every step is idempotent (`mkdir -p`, overwrite the config, `rm -f`), so a
 * re-run after a partial failure converges rather than compounding.
 *
 * The rule that matters: **the config is tested before anything is reloaded**,
 * and a failed test removes the config we just wrote. A broken vhost that
 * reaches a reload takes every other site on the box down with it — one user's
 * typo must never cost the whole server.
 */
class ApplicationProvisioner
{
    public function __construct(
        private ServerOps $serverOps,
        private WebServerManager $webServers,
        private InstallerManager $installers,
        private ProcessSupervisor $supervisor,
        private ProvisionProgress $progress,
        private AutoIssueCertificate $autoCertificate,
        private PoolManager $pools,
        private ApplicationArtifacts $artifacts,
        private HttpReadinessCheck $readiness,
    ) {}

    /**
     * The web root served by nginx/PHP.
     *
     * Uses the app slug as the directory name (not the domain), because slugs are
     * unique, stable, and survive a domain change without the filesystem path
     * breaking. The old panel uses the same convention.
     *
     * public_html is ALWAYS the fixed base. User's web_root (if set)
     * is appended inside public_html/.
     */
    public function documentRoot(Application $application): string
    {
        // The arithmetic itself lives on the model, so that a resource or a
        // form request can ask an application where it is served from without
        // resolving this service — which pulls in half the provisioning stack
        // to answer a question about two strings. The traversal guard stays
        // here, on the path that hands a directory to a shell command.
        $path = $application->documentRoot();

        abort_if(
            str_contains($path, '/../') || str_ends_with($path, '/..'),
            500,
        );

        return $path;
    }

    /**
     * Steps are recorded on the application as each one completes, not
     * collected and written at the end — the user is watching this happen, and
     * a failure halfway should leave behind how far it got.
     *
     * @param  bool  $skipInstaller  True for Site Clone: the vhost is written
     *                               and tested here same as any new site, but
     *                               the marketplace installer and starting
     *                               the process both need real files first —
     *                               a clone's files arrive afterward, via
     *                               `CloneManager`'s own rsync, not a fresh
     *                               install. Running either here would fight
     *                               that rsync or start a process against an
     *                               empty directory, the same failure mode
     *                               `startProcess()` already avoids for a
     *                               brand-new git application.
     * @return array<int, string> the steps completed, in order
     *
     * @throws ProvisioningFailedException
     */
    public function provision(Application $application, bool $skipInstaller = false): array
    {
        $driver = $this->webServers->driver();
        $user = $application->systemUser;
        $documentRoot = $this->documentRoot($application);

        $this->progress->open($application);

        // Before anything is created, because everything that follows assumes
        // this account exists: the directory is chowned to it, the vhost names
        // it, the installer runs as it.
        //
        // The `system_users` row is not proof — it records what the panel
        // created, and a server rebuilt under a surviving database, an adopted
        // box, or a `useradd` that failed somewhere the row outlived all leave
        // a username with no passwd entry. {@see PoolManager} learned this and
        // asked `getent` before writing a pool, and {@see CrontabManager}
        // before writing a cron file — but the check lived *inside* the pool
        // builder, and `PoolManager::supported()` is true for the FPM stack
        // only. On OpenLiteSpeed the pool step never runs, so nothing asked,
        // and `chown` exited 1 several steps later with a reference number in
        // place of "that account does not exist on this server".
        $this->step('check_account', fn (): ServerOpsResult => $this->serverOps->run(
            ['getent', 'passwd', (string) $user?->username],
            ['feature' => 'application', 'op' => 'account_check', 'application' => $application->id],
            timeout: 15,
        ));

        // One directory, for every site type there is. `documentRoot()` resolves
        // to the same flat `{root}/public_html/{web_root}` shape for all of
        // them.
        //
        // A git site used to be sent down a branch of its own here, which built
        // `releases/<timestamp>/public` and a `current` symlink instead. That
        // was the layout before `4059aa0` ("flat path for all apps, remove
        // current symlink") — and the refactor left this behind, so a git site
        // got a release structure nothing serves and never got the directory
        // everything actually uses. The next step chowned a path that did not
        // exist, `chown` exited 1, and **provisioning failed at
        // `set_ownership` for every git application** before a single git
        // command ran. The suite could not see it: `Process` is faked, so the
        // chown against a missing directory returned success.
        $this->step('create_directory', function () use ($application, $documentRoot, $user) {
            $result = $this->serverOps->run(
                ['mkdir', '-p', $documentRoot],
                ['feature' => 'application', 'op' => 'mkdir', 'application' => $application->id],
            );

            if ($result->failed() || $application->site_type !== 'git') {
                return $result;
            }

            // The shared `.env`, beside the application's own code and outside
            // the served directory — it holds the application's secrets, and
            // anything under the document root is a URL.
            //
            // Asked of `Application` rather than spelled out again. The comment
            // here used to say "`ApplicationEnvironment::path()` reads the same
            // location", which was true only for as long as two separate
            // strings happened to agree — and the WAF detect log, which had the
            // same arrangement, had already drifted into the panel opening a
            // file nothing wrote.
            //
            // Owned here rather than by `set_ownership` below, which only
            // descends the document root: `touch` runs elevated, and a
            // root-owned `.env` is one the site's own process cannot write and
            // the File Manager cannot edit.
            $env = $application->envPath();

            $result = $this->serverOps->run(
                ['touch', $env],
                ['feature' => 'application', 'op' => 'touch_env', 'application' => $application->id],
            );

            if ($result->failed()) {
                return $result;
            }

            return $this->serverOps->run(
                ['chown', "{$user->username}:{$user->username}", $env],
                ['feature' => 'application', 'op' => 'chown_env', 'application' => $application->id],
            );
        });

        // Written *before* ownership is set, not after. `tee` runs elevated,
        // so a placeholder written after the `chown` is a root-owned file
        // sitting in the site user's own directory: the File Manager runs as
        // that user and could list it but never edit, rename or delete it —
        // and on a blank site it is the one file they immediately want to
        // replace.
        if ($this->wantsPlaceholder($application, $skipInstaller)) {
            $this->step('placeholder', fn () => $this->serverOps->run(
                ['tee', $this->placeholderPath($application, $documentRoot)],
                ['feature' => 'application', 'op' => 'placeholder', 'application' => $application->id],
                input: $this->placeholderContents($application),
            ));
        }

        $this->step('set_ownership', fn () => $this->serverOps->run(
            ['chown', '-R', "{$user->username}:{$user->username}", $documentRoot],
            ['feature' => 'application', 'op' => 'chown', 'application' => $application->id],
        ));

        // Every PHP site gets its own pool from the start, not as an opt-in
        // afterthought. Before this, a freshly provisioned site ran on the
        // shared, server-wide pool as the web server's own account — which is
        // exactly what let a phpMyAdmin install's own config file (owned by
        // its site user, 0640) come back "not readable" in production. Doing
        // this *before* write_config, not after, means the vhost picks up
        // the isolated socket on its first render instead of needing a
        // second write+reload the way the manual isolate() action does.
        if ($application->serving_profile === 'php' && $this->pools->supported()) {
            $this->step('create_php_pool', function () use ($application) {
                $settings = $application->phpSettings
                    ?? new ApplicationPhpSettings(['application_id' => $application->id]);

                $result = $this->pools->apply($application, $settings);

                if (! $result['ok']) {
                    return new ServerOpsResult(false, (string) ($result['reference'] ?? ''), null);
                }

                $application->forceFill(['isolated_at' => now()])->save();

                return new ServerOpsResult(true, (string) ($result['reference'] ?? ''), null);
            });
        }

        $this->step('write_config', fn () => $driver->apply($application, $documentRoot));

        // Test before reload — and if the test fails, take our config back out
        // so the next reload (ours or anyone else's) is not poisoned by it.
        $test = $driver->test();

        if ($test->failed()) {
            // The driver's own removal, not `rm -f`: on a web server whose
            // site lives partly in a shared file, deleting the per-site file
            // would leave the shared one pointing at something gone — a config
            // that is still broken, and now broken in a harder way.
            $driver->remove($application);

            throw new ProvisioningFailedException('test_config', $test->reference);
        }

        $this->progress->record('test_config');

        $this->step('reload', fn () => $driver->reload());

        if (! $skipInstaller) {
            // Marketplace apps install once the site is actually being served
            // — WordPress writes its own URL into the database during setup,
            // so it needs the vhost live first. Site types with no installer
            // (git, blank PHP, static) record nothing here.
            $this->installers->install($application, $documentRoot);

            // The process last, because until the installer has run there is
            // nothing to start. Starting first was wrong in both directions: a
            // one-click Node app would be launched against an empty directory,
            // and a git application has no code at all until its first deploy —
            // `systemctl start` succeeds, the process dies immediately, and
            // provisioning fails on a site that is otherwise fine.
            $this->startProcess($application, $documentRoot);
        }

        // Last, and unable to fail the provision. The site is created, serving
        // and correct by this point; a DNS timeout must not turn that into a
        // failed application over a certificate nobody asked for. Declines
        // silently when the domain is not pointed here yet, which for a new
        // domain is most of the time — writing a failed certificate there
        // would make every new site look broken on its first screen.
        $this->autoCertificate->attempt($application->fresh(['domains', 'certificate']));

        // Count what was just installed. Nothing else will: a size is written
        // by a git deploy and by the file browser, so a one-click site nobody
        // has deployed or browsed had never been measured at all and read as
        // "Not measured" in the sites list for as long as it existed —
        // Nextcloud's several hundred MB included. Queued and debounced by the
        // job itself, so a `du` over a fresh WordPress does not sit inside
        // provisioning, and it cannot fail the site: {@see MeasureApplicationSize}
        // logs and gives up rather than throwing.
        MeasureApplicationSize::dispatch($application->id)
            ->delay(now()->addSeconds(MeasureApplicationSize::DEBOUNCE_SECONDS));

        return $this->progress->steps();
    }

    /**
     * Start the application's process, if there is one and there is anything
     * for it to run yet.
     *
     * A one-click application's start command is written here rather than
     * asked for: the installer knows the one right answer, and it needs the
     * document root to say it.
     *
     * A git application reaches this with a start command and an empty
     * directory. Its unit is written and enabled — so the port, the limits and
     * the boot behaviour are all in place — but it is not started, because the
     * code arrives with the first deploy, which starts it.
     *
     * @throws ProvisioningFailedException
     */
    private function startProcess(Application $application, string $documentRoot): void
    {
        $installer = $this->installers->installerFor($application);
        $command = $installer?->startCommand($application, $documentRoot);

        if ($command !== null) {
            $application->forceFill(['start_command' => $command])->save();
        }

        if (! $this->supervisor->runs($application)) {
            return;
        }

        // Installed applications have their code now; a git checkout does not.
        $ready = $command !== null;

        $this->supervisor->apply($application, $documentRoot, start: $ready);

        $this->progress->record($ready ? 'start_app' : 'write_unit');

        // Started is not the same as working, and the difference is the whole
        // reason this exists: a NodeBB whose assets never compiled runs, stays
        // up and satisfies every check above, while answering `500 Failed to
        // lookup view!` on every request. The panel called that site Active.
        //
        // Only for an application actually started here. A git checkout has no
        // code yet and is deliberately not running, so asking it for a page
        // would fail every time and mark a correct provision as broken.
        // Not via `step()`: that helper takes a callable returning a
        // `ServerOpsResult` and reads `failed()` on it. This check retries,
        // so it owns its own result and throws — recording the step after it
        // returns, which is the same rule `step()` follows.
        if ($ready) {
            $this->readiness->verify($application);

            $this->progress->record('verify_serving');
        }
    }

    /**
     * Remove the site's config and reload, so the domain stops being served.
     * Files are only deleted when explicitly asked for — losing a user's code
     * must never be a side effect of removing a panel record.
     */
    public function deprovision(Application $application, bool $removeFiles = false): void
    {
        // The process first: a unit left running holds its port and keeps
        // serving traffic for a site the panel has stopped listing.
        $this->supervisor->remove($application);

        // Then everything else the panel wrote outside the site's own
        // directory — the PHP-FPM pool, worker units, the fail2ban jail, the
        // certificate's renewal. Each of them outlives the application
        // otherwise, and each then points at something that is not there.
        // {@see ApplicationArtifacts} for what each one does when it does.
        //
        // `$removeFiles` is passed through because one of them — the site's
        // backups — is not a leftover but the copy that survives a mistake.
        // Those follow the same flag the files do, so a plain delete leaves
        // them recoverable and only an explicit "destroy this data" removes
        // them.
        $this->artifacts->remove($application, $removeFiles);

        $driver = $this->webServers->driver();

        $driver->remove($application);

        if ($removeFiles) {
            // Use slug, not domain — slug is unique and stable, domain is not.
            $this->serverOps->run(
                ['rm', '-rf', $application->rootPath()],
                ['feature' => 'application', 'op' => 'remove_files', 'application' => $application->id],
            );
        }

        // A failed test here means the server config was already broken before
        // us; reloading anyway would make that worse, so skip it.
        if ($driver->test()->ok) {
            $driver->reload();
        }
    }

    /**
     * Takes a site offline without deleting it: swaps its vhost to a small
     * built-in "unavailable" page, reversible via `enable()`. Nothing else —
     * files, database, a supervised process — is touched. This is a
     * web-facing pause, not a deprovision.
     *
     * Reuses the site's own *static* vhost template rather than writing a
     * fourth one per web server: `serving_profile` is temporarily set to
     * `static` in memory only (never persisted) for the duration of the
     * render, so the existing template's SSL, ACME-challenge and redirect
     * handling keep working for free instead of being reimplemented.
     */
    public function disable(Application $application): void
    {
        abort_if($application->disabled_at !== null, 422, __('errors/application.already_disabled'));

        $this->ensureDisabledPage();

        $driver = $this->webServers->driver();
        $realProfile = $application->serving_profile;

        $application->serving_profile = 'static';
        $applied = $driver->apply($application, $this->disabledPageRoot());
        $application->serving_profile = $realProfile;

        if ($applied->failed()) {
            throw new ApplicationAvailabilityException($applied->reference);
        }

        if ($driver->test()->failed()) {
            // Put the real vhost back before failing — a disable must never
            // leave a live site's config pointed nowhere useful.
            $restored = $driver->apply($application, $this->documentRoot($application));

            throw new ApplicationAvailabilityException($restored->reference);
        }

        $driver->reload();

        $application->disabled_at = now();
        $application->save();
    }

    public function enable(Application $application): void
    {
        abort_if($application->disabled_at === null, 422, __('errors/application.not_disabled'));

        $driver = $this->webServers->driver();
        $documentRoot = $this->documentRoot($application);

        $applied = $driver->apply($application, $documentRoot);

        if ($applied->failed()) {
            throw new ApplicationAvailabilityException($applied->reference);
        }

        if ($driver->test()->failed()) {
            // Put the disabled page back before failing, for the same reason
            // disable()'s rollback exists — never strand the vhost mid-swap.
            $realProfile = $application->serving_profile;
            $application->serving_profile = 'static';
            $restored = $driver->apply($application, $this->disabledPageRoot());
            $application->serving_profile = $realProfile;

            throw new ApplicationAvailabilityException($restored->reference);
        }

        $driver->reload();

        $application->disabled_at = null;
        $application->save();
    }

    private function disabledPageRoot(): string
    {
        return rtrim((string) config('server.disabled_page_root'), '/');
    }

    /** Idempotent — safe to call on every disable(), cheap when it already exists. */
    private function ensureDisabledPage(): void
    {
        $root = $this->disabledPageRoot();

        $this->serverOps->run(['mkdir', '-p', $root], ['feature' => 'application', 'op' => 'ensure_disabled_page']);

        $this->serverOps->run(
            ['tee', "{$root}/index.html"],
            ['feature' => 'application', 'op' => 'ensure_disabled_page'],
            input: '<!doctype html><meta charset="utf-8"><title>Unavailable</title>'
                ."<h1>This site is temporarily unavailable</h1>\n",
        );
    }

    /**
     * A placeholder is a courtesy for a site with nothing in it yet. Two kinds
     * of site must not get one:
     *
     *  - a git site, whose content arrives from the repository. `git clone`
     *    refuses a non-empty destination outright, so a placeholder here does
     *    not merely sit there looking untidy — it breaks the first deploy of
     *    every git application, which is the only deploy that matters for a
     *    site nobody has used yet.
     *  - a clone target, which is about to have another site's files rsynced
     *    over it.
     */
    private function wantsPlaceholder(Application $application, bool $skipInstaller): bool
    {
        return ! $skipInstaller && $application->site_type !== 'git';
    }

    private function placeholderPath(Application $application, string $documentRoot): string
    {
        return $documentRoot.'/'.($application->serving_profile === 'php' ? 'index.php' : 'index.html');
    }

    private function placeholderContents(Application $application): string
    {
        $domain = e($application->domain);

        $body = "<!doctype html><meta charset=\"utf-8\"><title>{$domain}</title>"
            ."<h1>{$domain}</h1><p>This site is ready. Upload your files or deploy your code.</p>";

        return $application->serving_profile === 'php'
            ? "<?php // Placeholder written by the panel — replace with your app.\n?>\n".$body."\n"
            : $body."\n";
    }

    /**
     * @param  callable(): ServerOpsResult  $operation
     *
     * @throws ProvisioningFailedException
     */
    private function step(string $name, callable $operation): void
    {
        $result = $operation();

        if ($result->failed()) {
            throw new ProvisioningFailedException($name, $result->reference);
        }

        $this->progress->record($name);
    }
}
