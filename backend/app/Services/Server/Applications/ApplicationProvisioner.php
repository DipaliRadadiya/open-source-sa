<?php

namespace App\Services\Server\Applications;

use App\Actions\Server\Application\AutoIssueCertificate;
use App\Actions\Server\SystemUser\CreateSystemUser;
use App\Contracts\WebServerDriver;
use App\Exceptions\Server\Application\ApplicationAvailabilityException;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Jobs\MeasureApplicationSize;
use App\Models\Application;
use App\Models\ApplicationPhpSettings;
use App\Services\Server\Docker\DockerResources;
use App\Services\Server\Php\PoolManager;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

/**
 * Puts an application on the server: directory, ownership, site config, and a tested reload.
 *
 * Every step is idempotent (`mkdir -p`, overwrite the config, `rm -f`), so a re-run after a partial
 * failure converges rather than compounding.
 *
 * The rule that matters: **the config is tested before anything is reloaded**, and a failed test
 * removes the config we just wrote. A broken vhost that reaches a reload takes every other site on
 * the box down with it.
 */
class ApplicationProvisioner
{
    public function __construct(
        private CreateSystemUser $createSystemUser,
        private ServerOps $serverOps,
        private WebServerManager $webServers,
        private InstallerManager $installers,
        private ProcessSupervisor $supervisor,
        private ContainerSupervisor $containers,
        private ProvisionProgress $progress,
        private AutoIssueCertificate $autoCertificate,
        private PoolManager $pools,
        private ApplicationArtifacts $artifacts,
        private HttpReadinessCheck $readiness,
        private SiteRootLock $rootLock,
        private SecretFilePrivacy $secretPrivacy,
    ) {}

    /**
     * The web root served by nginx/PHP.
     *
     * Uses the app slug as the directory name (not the domain): slugs are unique and stable, and
     * survive a domain change. `public_html` is ALWAYS the fixed base; the user's web_root (if set)
     * is appended inside it.
     */
    public function documentRoot(Application $application): string
    {
        // The arithmetic lives on the model so a resource or form request can ask where an
        // application is served from without resolving this service. The traversal guard stays
        // here, on the path that is handed to a shell command.
        $path = $application->documentRoot();

        abort_if(
            str_contains($path, '/../') || str_ends_with($path, '/..'),
            500,
        );

        return $path;
    }

    /**
     * The application's own root: where its code lives, which is not always where it is served from.
     *
     * Beside `documentRoot()` for the same reason: the arithmetic is on the model, and the traversal
     * guard belongs on the path handed to a shell command (Site Clone rsyncs this directory).
     */
    public function codePath(Application $application): string
    {
        $path = $application->codePath();

        abort_if(
            str_contains($path, '/../') || str_ends_with($path, '/..'),
            500,
        );

        return $path;
    }

    /**
     * The directory that *is* the application: `{home}/{slug}/public_html`.
     *
     * `documentRoot()` is what the web server hands out and `codePath()` is where the application's
     * own commands run; neither is "what are this site's files", which is what a backup asks. For a
     * Laravel or Craft site both point *inside* the application, so copying them copies the public
     * folder and calls it the site.
     *
     * `web_root` selects what is served inside this directory without moving it. `.panel` (trash,
     * file backups, the htpasswd file) is a sibling, so it stays out of anything that copies this.
     *
     * Same traversal guard as the two above: this hands a directory to a shell command.
     */
    public function applicationRoot(Application $application): string
    {
        $path = $application->publicHtmlPath();

        abort_if(
            str_contains($path, '/../') || str_ends_with($path, '/..'),
            500,
        );

        return $path;
    }

    /**
     * Steps are recorded on the application as each one completes, not collected and written at the
     * end: the user is watching, and a failure halfway should leave behind how far it got.
     *
     * @param  bool  $skipInstaller  True for Site Clone: the vhost is written and tested as for any new
     *                               site, but the marketplace installer and starting the process need
     *                               real files first, and a clone's files arrive afterward via
     *                               `CloneManager`'s rsync. Running either here would fight that rsync
     *                               or start a process against an empty directory.
     * @return array<int, string> the steps completed, in order
     *
     * @throws ProvisioningFailedException
     */
    public function provision(Application $application, bool $skipInstaller = false): array
    {
        // Provisioning builds the top level of the site root (public_html, .panel, logs, the .env, an
        // installer's data directory), so the immutable flag is off for all of it: a Retry Setup on a
        // locked site would otherwise fail its first mkdir. Locked again whatever happens, so a setup
        // that dies halfway does not leave the site root renamable by its user. {@see SiteRootLock}
        $this->rootLock->unlock($application);

        try {
            return $this->runProvisioning($application, $skipInstaller);
        } finally {
            $locked = $this->rootLock->lock($application);

            if (! in_array($locked, [SiteRootLock::LOCKED, SiteRootLock::UNSUPPORTED, SiteRootLock::MISSING], true)) {
                Log::channel('server-ops')->warning('site root not locked after provisioning', [
                    'application' => $application->id,
                    'result' => $locked,
                ]);
            }
        }
    }

    /**
     * @return array<int, string>
     *
     * @throws ProvisioningFailedException
     */
    private function runProvisioning(Application $application, bool $skipInstaller): array
    {
        $driver = $this->webServers->driver();
        $user = $application->systemUser;
        $documentRoot = $this->documentRoot($application);

        $this->progress->open($application);

        // Before anything is created, because everything that follows assumes this account exists:
        // the directory is chowned to it, the vhost names it, the installer runs as it.
        //
        // The `system_users` row is not proof: a rebuilt server under a surviving database, an
        // adopted box, or a failed `useradd` all leave a username with no passwd entry.
        // {@see PoolManager} and {@see CrontabManager} check with `getent`, but the pool step runs for
        // the FPM stack only, so on OpenLiteSpeed nothing asked and `chown` exited 1 several steps
        // later. This creates the account when it is missing (it is also where an account the panel
        // generated for the site comes into being: `CreateApplication` writes nothing to the
        // server). Idempotent: an existing account is left exactly as it is.
        $this->step('ensure_account', fn (): ServerOpsResult => $user === null
            ? $this->serverOps->run(
                ['getent', 'passwd', ''],
                ['feature' => 'application', 'op' => 'account_check', 'application' => $application->id],
                timeout: 15,
            )
            : $this->createSystemUser->ensureOnServer($user));

        // One directory for every site type: `documentRoot()` resolves to the same flat
        // `{root}/public_html/{web_root}` shape for all of them. A git site used to be sent down a
        // branch that built `releases/<timestamp>/public` and a `current` symlink, which nothing
        // serves any more, so `set_ownership` chowned a path that did not exist and failed for
        // every git application. The suite could not see it: `Process` is faked, so the chown
        // returned success.
        $this->step('create_directory', function () use ($application, $documentRoot, $user) {
            $result = $this->serverOps->run(
                ['mkdir', '-p', $documentRoot],
                ['feature' => 'application', 'op' => 'mkdir', 'application' => $application->id],
            );

            // Git sites need it because their code reads it. A container needs it because the
            // generated compose file declares `env_file` unconditionally and Compose treats a
            // missing one as fatal, so no container site built from the image/port fields could
            // start. (The suite fakes `Process`, and the only container site on the test box used a
            // PASTED compose file, which has no `env_file`.)
            $needsEnv = $application->site_type === 'git'
                || $application->serving_profile === 'docker';

            if ($result->failed() || ! $needsEnv) {
                return $result;
            }

            // The shared `.env`, beside the application's own code and outside the served
            // directory: it holds secrets, and anything under the document root is a URL. Asked of
            // `Application` rather than spelled out again, so two strings cannot drift apart.
            //
            // Owned here rather than by `set_ownership` below, which only descends the document
            // root: `touch` runs elevated, and a root-owned `.env` is one the site's own process
            // cannot write and the File Manager cannot edit.
            $env = $application->envPath();

            $result = $this->serverOps->run(
                ['touch', $env],
                ['feature' => 'application', 'op' => 'touch_env', 'application' => $application->id],
            );

            if ($result->failed()) {
                return $result;
            }

            // `-h`: where the site has a web root, `.env` sits in a folder the site user owns, and
            // a plain chown follows a link planted there, handing its target to the user. With -h a
            // link is changed itself, never followed.
            return $this->serverOps->run(
                ['chown', '-h', "{$user->username}:{$user->username}", $env],
                ['feature' => 'application', 'op' => 'chown_env', 'application' => $application->id],
            );
        });

        $this->step('set_ownership', fn () => $this->serverOps->run(
            ['chown', '-R', "{$user->username}:{$user->username}", $documentRoot],
            ['feature' => 'application', 'op' => 'chown', 'application' => $application->id],
        ));

        // Written *as the site user*, after ownership is set: this runs on every provision ("Retry
        // setup" too) into a document root the user already controls, and root's `tee` follows a link
        // planted at `index.php`. As the user the file is theirs by construction.
        if ($this->wantsPlaceholder($application, $skipInstaller)) {
            $this->step('placeholder', fn () => $this->serverOps->run(
                ['runuser', '-u', $user->username, '--', 'tee', $this->placeholderPath($application, $documentRoot)],
                ['feature' => 'application', 'op' => 'placeholder', 'application' => $application->id],
                input: $this->placeholderContents($application),
            ));
        }

        // Every PHP site gets its own pool from the start: on the shared server-wide pool a site
        // ran as the web server's account, so a phpMyAdmin config file owned by its site user
        // (0640) came back "not readable". Doing this *before* write_config means the vhost picks
        // up the isolated socket on its first render instead of needing a second write+reload.
        //
        // Every PHP site starts with the strict `disable_functions` list: exec, shell_exec, system,
        // passthru, proc_open and popen are what a web shell needs and almost no application does.
        //
        // PERSISTED HERE, NOT CHANGED IN defaults(): `defaults()` fills every value a site has not
        // set, so moving the list there would silently apply it to existing sites on whatever
        // unrelated event next re-rendered their configuration. Writing a row at creation confines
        // it to new sites and leaves the row visible and editable.
        //
        // Before create_php_pool and write_config, deliberately: FPM renders these into the pool
        // and OpenLiteSpeed into the site's ini, so written after them the first render would ship
        // without the list.
        if ($application->serving_profile === 'php') {
            $this->step('harden_php', function () use ($application) {
                $settings = ApplicationPhpSettings::firstOrNew(
                    ['application_id' => $application->id],
                );

                // A DEFAULT, NOT AN OVERRIDE. A clone arrives with its source's settings already
                // copied onto it, and provisioning must not undo that (a site whose owner relaxed
                // the list to make a plugin work would come back strict).
                //
                // `=== null` rather than `empty()` or `blank()`: an empty string is a real answer
                // ("this site disables nothing"), and treating it as unset would re-harden the one
                // site whose owner had said no.
                if ($settings->disable_functions === null) {
                    $settings->disable_functions = ApplicationPhpSettings::strictDisabledFunctionsFor((string) $application->site_type);

                    // What the site was given, kept apart from what it has now so "changed" is
                    // judged against this and not a preset a later release may have moved. Written
                    // only alongside the list it describes: a clone arrives with its source's pair
                    // and keeps it.
                    $settings->provisioned_disable_functions = $settings->disable_functions;
                }

                // open_basedir on for a site the panel creates, as v7 does. Here and not as a column
                // default: the column says off for every existing row, and a clone arrives with its
                // source's row already saved, so `exists` tells the two apart.
                if (! $settings->exists) {
                    $settings->open_basedir_enabled = true;
                }

                $settings->save();

                // The relation was loaded before the row existed; the pool step below reads it, and
                // a stale null would write a pool without the list and leave the database
                // disagreeing with the file.
                $application->unsetRelation('phpSettings');

                return new ServerOpsResult(true, '', null);
            });
        }

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

        $this->writeTestedConfig($driver, $application, $documentRoot);
        $this->progress->record('test_config');

        $this->step('reload', fn () => $driver->reload());

        if (! $skipInstaller) {
            // Marketplace apps install once the site is actually being served: WordPress writes its
            // own URL into the database during setup, so it needs the vhost live first. Site types
            // with no installer (git, blank PHP, static) record nothing here.
            $this->installers->install($application, $documentRoot);

            // The process last, because until the installer has run there is nothing to start: a
            // one-click Node app would be launched against an empty directory, and a git
            // application has no code until its first deploy, so the process would die immediately
            // and fail an otherwise fine site.
            $allocatedPort = $application->app_port;

            $this->startProcess($application, $documentRoot);

            // A pasted compose file publishes its own port, and starting it moves `app_port` there,
            // after write_config proxied to the allocated one: a 502 for good when the domain is
            // not pointed here yet (the certificate step, which would rewrite the vhost, declines).
            // Same reconcile as UpdateContainerCompose.
            $port = $application->fresh()?->app_port;

            if ($port !== $allocatedPort) {
                $application->app_port = $port;

                $this->writeTestedConfig($driver, $application, $documentRoot);

                $this->step('reload', fn () => $driver->reload());
            }

            // After everything that may write a `.env` (installers and the first-start step alike).
            // Several leave it 0644.
            $this->secretPrivacy->narrow($application);
        }

        // Last, and unable to fail the provision: the site is created and serving, and a DNS
        // timeout must not turn that into a failed application. Declines silently when the domain
        // is not pointed here yet, which for a new domain is most of the time; a failed certificate
        // would make every new site look broken.
        $this->autoCertificate->attempt($application->fresh(['domains', 'certificate']));

        // Count what was just installed: a size is written only by a git deploy and the file
        // browser, so a one-click site nobody deployed or browsed stayed "Not measured". Queued and
        // debounced by the job, so a `du` over a fresh install does not sit inside provisioning,
        // and it cannot fail the site: {@see MeasureApplicationSize}
        MeasureApplicationSize::dispatch($application->id)
            ->delay(now()->addSeconds(MeasureApplicationSize::DEBOUNCE_SECONDS));

        return $this->progress->steps();
    }

    /**
     * Write the site's config and test the server's, before anything reloads.
     *
     * On a failure (the test, or a write that stopped after the first file was already linked) the
     * site's config comes back out: left enabled, one broken site failed `test_config` for every site
     * created after it. The error stays on this site.
     *
     * @throws ProvisioningFailedException
     */
    private function writeTestedConfig(WebServerDriver $driver, Application $application, string $documentRoot): void
    {
        try {
            $this->step('write_config', fn () => $driver->apply($application, $documentRoot));
        } catch (ProvisioningFailedException $exception) {
            $this->removeFailedConfig($driver, $application);

            throw $exception;
        }

        $test = $driver->test();

        if ($test->failed()) {
            $this->removeFailedConfig($driver, $application);

            throw new ProvisioningFailedException('test_config', $test->reference);
        }
    }

    /**
     * The driver's own removal, not `rm -f`: on a web server whose site lives partly in a shared
     * file, deleting the per-site file would leave the shared one pointing at something gone.
     *
     * Then the test again, so a removal that did not take, or a config that was broken before this
     * site, is in the log rather than discovered by the next site to fail.
     */
    private function removeFailedConfig(WebServerDriver $driver, Application $application): void
    {
        $removed = $driver->remove($application);
        $retest = $driver->test();

        if ($removed->failed() || $retest->failed()) {
            Log::channel('server-ops')->warning('web server config still failing after removing a failed site', [
                'application' => $application->id,
                'remove_reference' => $removed->reference,
                'test_reference' => $retest->reference,
            ]);
        }
    }

    /**
     * Start the application's process, if there is one and there is anything for it to run yet.
     *
     * A one-click application's start command is written here rather than asked for: the installer
     * knows the one right answer, and it needs the document root to say it.
     *
     * A git application reaches this with a start command and an empty directory. Its unit is
     * written and enabled (port, limits and boot behaviour in place) but not started: the code
     * arrives with the first deploy, which starts it.
     *
     * @throws ProvisioningFailedException
     */
    private function startProcess(Application $application, string $documentRoot): void
    {
        // A container is supervised by compose, not by systemd. `ContainerSupervisor` existed and
        // was tested but never called from here, so a Docker application reached "active" with no
        // container and nginx proxying to a port nothing listened on.
        if ($application->serving_profile === 'docker') {
            // The network and the volumes BEFORE the container: the compose file declares them
            // `external: true`, so Compose looks them up and refuses rather than creating them.
            app(DockerResources::class)->ensureFor($application);

            // The env vars the site was created with, into the file compose reads, before the
            // container first reads it.
            $this->writeContainerEnv($application);

            // The first pull of every image happens in this `up`.
            $this->containers->apply($application, $documentRoot, install: true);
            $this->progress->record('start_app');

            // The post-start hook, which this early return used to skip. A no-op for every app that
            // passes its admin credential as an environment variable, which is all of them but
            // Chatwoot (its production seeds create no user, only a flag opening a setup page to
            // whoever asks first).
            //
            // Not preceded by `readiness->verify()` as on the systemd path: that check has its own
            // timeout and container apps reach Active without it; `afterStart()` does its own
            // waiting, for as long as a first-boot migration takes.
            $this->installers->afterStart($application, $documentRoot);

            return;
        }

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

        // Started is not the same as working: a NodeBB whose assets never compiled runs and passes
        // every check above while answering `500 Failed to lookup view!` on every request.
        //
        // Only for an application actually started here: a git checkout has no code yet and is
        // deliberately not running, so asking it for a page would mark a correct provision as
        // broken. Not via `step()`, which expects a `ServerOpsResult`: this check retries, so it
        // owns its result and throws, recording the step after it returns (the same rule `step()`
        // follows).
        if ($ready) {
            $this->readiness->verify($application);

            $this->progress->record('verify_serving');

            // After the site answers and before it is reported ready: an application whose admin is
            // created by the first visitor must not be Active while a stranger can still do that.
            $this->installers->afterStart($application, $documentRoot);
        }
    }

    /**
     * Write a container site's create-time env vars to its env file, once.
     *
     * The same file the Environment screen edits. Removed from the encrypted column as soon as it
     * is on disk, so a Retry after a failed start does not overwrite what the user has since fixed
     * there.
     *
     * @throws ProvisioningFailedException
     */
    private function writeContainerEnv(Application $application): void
    {
        $secrets = (array) ($application->install_secrets ?? []);
        $pairs = (array) ($secrets[Application::CONTAINER_ENV_SECRET] ?? []);

        if ($pairs === []) {
            return;
        }

        $lines = array_map(
            fn ($pair): string => self::envLine((string) ($pair[0] ?? ''), (string) ($pair[1] ?? '')),
            $pairs,
        );

        try {
            app(ApplicationEnvironment::class)->write($application, implode("\n", $lines)."\n", keepPrevious: false);
        } catch (\RuntimeException) {
            // The value never reaches the log; the step name is the answer.
            throw new ProvisioningFailedException('write_env', '');
        }

        unset($secrets[Application::CONTAINER_ENV_SECRET]);

        $application->forceFill(['install_secrets' => $secrets === [] ? null : $secrets])->save();

        $this->progress->record('write_env');
    }

    /**
     * One `KEY=value` line Compose reads back as exactly the value typed.
     *
     * Compose interpolates `$` in env files and reads quotes and `#`, so a
     * value is never written bare. Single quotes are literal; a value that
     * holds one, or a line break, goes in double quotes with the escapes
     * Compose's dotenv parser undoes (`\\`, `\"`, `\n`, `\$`).
     */
    public static function envLine(string $key, string $value): string
    {
        $value = str_replace("\r\n", "\n", $value);

        if (! str_contains($value, "'") && ! str_contains($value, "\n")) {
            return $key."='".$value."'";
        }

        return $key.'="'.strtr($value, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', '$' => '\\$']).'"';
    }

    /**
     * Remove the site's config and reload, so the domain stops being served.
     * Files are only deleted when explicitly asked for — losing a user's code
     * must never be a side effect of removing a panel record.
     */
    public function deprovision(
        Application $application,
        bool $removeFiles = false,
        bool $removeDockerVolumes = false,
    ): void {
        // Off for good, whether the files go or stay: `rm -rf` cannot remove an immutable
        // directory, and one left in the user's home that nothing manages would be one they could
        // never delete.
        $this->rootLock->unlock($application);

        // The process first: a unit left running holds its port and keeps serving traffic for a
        // site the panel has stopped listing. A container does the same, so both are asked;
        // `remove()` on either is a no-op when there was nothing of that kind.
        $this->supervisor->remove($application);

        if ($application->serving_profile === 'docker') {
            // The volumes flag rides along here: `compose down` is the only thing that knows which
            // volumes a PASTED file declared.
            $this->containers->remove(
                $application,
                $this->documentRoot($application),
                $removeDockerVolumes,
            );
        }

        // Then everything else the panel wrote outside the site's own directory (the PHP-FPM pool,
        // worker units, the fail2ban jail, the certificate's renewal), each of which would
        // otherwise outlive the application. {@see ApplicationArtifacts}
        //
        // `$removeFiles` is passed through because the site's backups are the copy that survives a
        // mistake: a plain delete leaves them recoverable and only an explicit "destroy this data"
        // removes them.
        $this->artifacts->remove($application, $removeFiles);

        // Every unit that lived in the application's slice is gone by now (its own above, its workers
        // with the artifacts), so the slice can go. systemd will not reclaim it on its own.
        $this->supervisor->releaseSlice($application);

        $driver = $this->webServers->driver();

        $driver->remove($application);

        // Not without a system user: the files' location is the user's home,
        // and without one it is unknown — see Application::rootPath().
        if ($removeFiles && $application->systemUser !== null) {
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
     * Takes a site offline without deleting it: swaps its vhost to a small built-in "unavailable"
     * page, reversible via `enable()`. Files, database and a supervised process are not touched;
     * this is a web-facing pause, not a deprovision.
     *
     * Reuses the site's own *static* vhost template rather than writing a fourth one per web server:
     * `serving_profile` is set to `static` in memory only (never persisted) for the render, so the
     * template's SSL, ACME-challenge and redirect handling keep working.
     */
    public function disable(Application $application): void
    {
        abort_if($application->disabled_at !== null, 422, __('errors/application.already_disabled'));

        $this->ensureDisabledPage();

        $driver = $this->webServers->driver();
        $realProfile = $application->serving_profile;

        // Set before rendering, unsaved: the template reads it to serve the
        // page as 503. Cleared again on every way out that fails.
        $application->disabled_at = now();
        $application->serving_profile = 'static';
        $applied = $driver->apply($application, $this->disabledPageRoot());
        $application->serving_profile = $realProfile;

        if ($applied->failed()) {
            $application->disabled_at = null;

            throw new ApplicationAvailabilityException($applied->reference);
        }

        if ($driver->test()->failed()) {
            // Put the real vhost back before failing — a disable must never
            // leave a live site's config pointed nowhere useful.
            $application->disabled_at = null;
            $restored = $driver->apply($application, $this->documentRoot($application));

            throw new ApplicationAvailabilityException($restored->reference);
        }

        $driver->reload();

        $application->save();

        // A Node app went on running behind the unavailable page, reachable on its port past the
        // vhost. Stopped, and kept stopped across a reboot, once the page is live; started again by
        // enable().
        if ($this->supervisor->runs($application)) {
            $stopped = $this->supervisor->suspend($application);

            if ($stopped->failed()) {
                throw new ApplicationAvailabilityException($stopped->reference);
            }
        }
    }

    public function enable(Application $application): void
    {
        abort_if($application->disabled_at === null, 422, __('errors/application.not_disabled'));

        $driver = $this->webServers->driver();
        $documentRoot = $this->documentRoot($application);
        $disabledAt = $application->disabled_at;

        // Domain/certificate changes while disabled update stored compose but
        // must not start a site. Publish that state before exposing its vhost.
        if ($application->serving_profile === 'docker') {
            try {
                $this->containers->apply($application, $documentRoot);
            } catch (ProvisioningFailedException $exception) {
                // The reason travels too: "the container keeps restarting" is something the user
                // can act on; a bare reference is not.
                throw new ApplicationAvailabilityException($exception->reference, $exception->reason);
            }
        }

        // The process first: the real vhost proxies to it.
        if ($this->supervisor->runs($application)) {
            $started = $this->supervisor->resume($application);

            if ($started->failed()) {
                throw new ApplicationAvailabilityException($started->reference);
            }
        }

        $application->disabled_at = null;
        $applied = $driver->apply($application, $documentRoot);

        if ($applied->failed()) {
            $application->disabled_at = $disabledAt;

            throw new ApplicationAvailabilityException($applied->reference);
        }

        if ($driver->test()->failed()) {
            // Put the disabled page back before failing, for the same reason
            // disable()'s rollback exists — never strand the vhost mid-swap.
            $application->disabled_at = $disabledAt;
            $realProfile = $application->serving_profile;
            $application->serving_profile = 'static';
            $restored = $driver->apply($application, $this->disabledPageRoot());
            $application->serving_profile = $realProfile;

            throw new ApplicationAvailabilityException($restored->reference);
        }

        $driver->reload();

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
            input: $this->disabledPageContents(),
        );
    }

    /**
     * The page a disabled site serves.
     *
     * The same markup as the placeholder and deliberately different words, because the reader is
     * different: visitors who did not choose to be here and cannot do anything about it. So there
     * are no next steps and nothing that says *why*, which is the site owner's business.
     *
     * Rewritten on every disable rather than only when missing, so a change to this page reaches
     * servers that already have one.
     */
    private function disabledPageContents(): string
    {
        return View::make('server.pages.page', [
            'kind' => 'disabled',
            'locale' => str_replace('_', '-', app()->getLocale()),
            'title' => __('application.disabled_page.title'),
            'heading' => __('application.disabled_page.heading'),
            'lede' => __('application.disabled_page.lede'),
            'dynamic' => null,
            'steps' => [],
            'foot' => __('application.disabled_page.foot'),
        ])->render()."\n";
    }

    /**
     * A placeholder is a courtesy for a site with nothing in it yet. Two kinds of site must not get
     * one:
     *
     *  - a git site, whose content arrives from the repository: `git clone` refuses a non-empty
     *    destination, so a placeholder would break the first deploy of every git application.
     *  - a clone target, which is about to have another site's files rsynced over it.
     */
    private function wantsPlaceholder(Application $application, bool $skipInstaller): bool
    {
        return ! $skipInstaller && $application->site_type !== 'git';
    }

    private function placeholderPath(Application $application, string $documentRoot): string
    {
        return $documentRoot.'/'.($application->serving_profile === 'php' ? 'index.php' : 'index.html');
    }

    /**
     * The page a brand-new site serves until its owner puts something there.
     *
     * **For a PHP site it also proves PHP is running**, by printing a value only PHP could produce:
     * a static file served from a misconfigured pool looks exactly like a working site until the
     * first real request. Static profiles get the same page without that line.
     *
     * The PHP fragment is passed in rather than written in the template, because Blade *executes* a
     * `<?php` block it finds in a view. It uses `date()` alone: this file runs on the customer's site
     * with no framework under it.
     */
    private function placeholderContents(Application $application): string
    {
        $isPhp = $application->serving_profile === 'php';

        $body = View::make('server.pages.page', [
            'kind' => 'placeholder',
            'locale' => str_replace('_', '-', app()->getLocale()),
            'title' => $application->domain,
            'heading' => $application->domain,
            'lede' => __('application.placeholder_page.lede'),
            'dynamic' => $isPhp
                ? '<?php echo htmlspecialchars(date("Y-m-d H:i:s T")); ?>'.' — '.e(__('application.placeholder_page.php_running'))
                : null,
            'steps' => [
                [
                    'title' => __('application.placeholder_page.step_files_title'),
                    'body' => __('application.placeholder_page.step_files_body'),
                ],
                [
                    'title' => __('application.placeholder_page.step_deploy_title'),
                    'body' => __('application.placeholder_page.step_deploy_body'),
                ],
            ],
            'foot' => __('application.placeholder_page.foot'),
        ])->render();

        // The comment is for whoever opens the file next, and has to be first: a PHP site's
        // placeholder is `index.php`, and a byte before `<?php` is output the browser receives.
        return $isPhp
            ? "<?php // Placeholder written by the panel — replace it with your application.\n?>\n".$body."\n"
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
