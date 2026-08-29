<?php

namespace App\Services\Server\WebServers;

use App\Contracts\PhpStack;
use App\Enums\DomainType;
use App\Models\Application;
use App\Services\Server\Applications\ApplicationLogDirectory;
use App\Services\Server\Certificates\CertificateFiles;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;

/**
 * OpenLiteSpeed.
 *
 * Two things make it unlike the other two drivers, and both are handled here
 * rather than leaking into the provisioner:
 *
 *  - **A site is a directory, not a file.** The config lives at
 *    `conf/vhosts/<name>/vhconf.conf`, so the directory has to exist first.
 *  - **A site is also two entries in the shared httpd_config.conf**, which is
 *    what `OlsSharedConfig` exists for.
 *
 * The site name is the application slug — see AbstractWebServerDriver. It has
 * to be the same string in three places (the config directory, the vhost name
 * in httpd_config.conf, and the `map` entry), which is why it comes from one
 * `fileName()` rather than being spelled out at each call site.
 *
 * ⚠️ **Unverified against a real OpenLiteSpeed server.** Every path, command
 * and directive here comes from LiteSpeed's documentation and OLS's own
 * shipped config, not from observation — there was no OLS box available when
 * this was written. That is why every path is in `config/server.php`: a wrong
 * one is a config change, not a code change. Treat the first run on a real box
 * as the actual test.
 */
class OlsDriver extends AbstractWebServerDriver
{
    public function __construct(
        ServerOps $serverOps,
        ManagedFile $files,
        CertificateFiles $certificateFiles,
        ApplicationLogDirectory $logDirectory,
        private OlsSharedConfig $shared,
        private PhpStack $stack,
    ) {
        parent::__construct($serverOps, $files, $certificateFiles, $logDirectory);
    }

    /**
     * The template needs the interpreter itself, not a socket path.
     *
     * nginx and Apache hand PHP to a pool that is already running and already
     * knows which binary it is. OLS starts the process, so the vhost has to
     * name the binary and the user — and `lsphp84`, not `lsphp8.4`.
     *
     * @return array<string, mixed>
     */
    protected function viewData(Application $application, string $documentRoot): array
    {
        $data = parent::viewData($application, $documentRoot);
        $version = (string) $data['phpVersion'];

        return [
            ...$data,
            // Site types whose cache or rewrite integration goes through
            // .htaccess. WordPress is the one that matters: LiteSpeed Cache
            // talks to the OLS cache module through that file and nothing else.
            'readsHtaccess' => in_array(
                $application->site_type,
                (array) config('server.web_server_drivers.openlitespeed.htaccess_site_types', ['wordpress']),
                true,
            ),
            'lsphpVersion' => str_replace('.', '', $version),
            // The LSAPI binary, not the CLI — this is what OLS spawns.
            'lsphpBinary' => $this->stack->handlerPath($version),
        ];
    }

    /**
     * Not yet. OpenLiteSpeed needs the rules as rewrite directives inside each
     * site's `vhconf.conf` — `.htaccess` would need a restart, not a reload —
     * and none of the three OLS templates carry them. Answering `false` here is
     * what turns "enabled and doing nothing" into a refusal the user can see.
     */
    public function supportsWaf(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'openlitespeed';
    }

    /**
     * Per-site directory, unlike the single file the other drivers write.
     */
    public function configPath(Application $application): string
    {
        $root = rtrim((string) config('server.web_server_drivers.openlitespeed.vhost_root', '/usr/local/lsws/conf/vhosts'), '/');

        // Keyed by the application, same as the other drivers — see
        // AbstractWebServerDriver::configPath(). `vhRoot()` deliberately does
        // not follow: that is the document root, which is where the site's
        // files actually live, and it is addressed by domain everywhere else.
        return "{$root}/{$this->fileName($application)}/vhconf.conf";
    }

    /**
     * Directory, then vhost file, then the shared entries — in that order.
     *
     * The shared file goes last on purpose. It is the one that can take every
     * site on the box down, so it is only touched once the per-site config it
     * points at is actually on disk; a `virtualHost` block naming a file that
     * does not exist fails the config test and would strand the whole server
     * rather than just this site.
     */
    public function apply(Application $application, string $documentRoot): ServerOpsResult
    {
        $context = ['feature' => 'application', 'op' => 'write_config', 'application' => $application->id];
        $fallback = $this->ensureTlsFallback($application);

        if ($fallback->failed()) {
            return $fallback;
        }

        // This driver overrides apply(), so it does not inherit the base
        // class's guarantee that `.panel/` exists before a config naming it
        // goes live.
        $this->ensurePanelDirectory($application);

        // The log directory the vhost names. OpenLiteSpeed does not create it —
        // it silently falls back to the server-wide log, so a site's own errors
        // go somewhere nobody thinks to look.
        //
        // Through the shared service rather than the `mkdir` that used to be
        // folded into the line below: this directory now holds every log for
        // the site and its ownership is what stops the site user replacing a
        // file a root process appends to. A bare `mkdir` here would leave OLS
        // the one web server whose log directory had the wrong owner.
        $this->logDirectory->ensure($application);

        $directory = $this->serverOps->run(
            ['mkdir', '-p', dirname($this->configPath($application))],
            $context,
        );

        if ($directory->failed()) {
            return $directory;
        }

        $written = $this->files->put(
            $this->configPath($application),
            $this->renderConfig($application, $documentRoot),
            $context,
        );

        if ($written->failed()) {
            return $written;
        }

        // The vhost NAME in the shared config, which must agree with the
        // directory `configPath()` writes into — both are the application, not
        // one of its domains. The domains it answers to are the second
        // argument, and those stay domains.
        return $this->shared->register(
            $this->fileName($application),
            $this->domains($application),
            $this->vhRoot($application),
        );
    }

    /**
     * The site's own directory — the one `restrained 1` confines the vhost to,
     * and the one its logs live under. Not the config directory.
     */
    private function vhRoot(Application $application): string
    {
        // Slug, not domain. `restrained 1` confines the vhost to this
        // directory, and the document root is `{root}/public_html/…` — so a
        // domain-named root put the document root *outside* the restraint,
        // and moved the site's logs every time a domain changed.
        return $application->rootPath();
    }

    /**
     * Shared entries first this time, then the files.
     *
     * The mirror image of `apply()`, for the same reason: stop OLS referring to
     * the site before removing what it refers to. The other order leaves a
     * `virtualHost` pointing at a deleted file, which fails the config test and
     * blocks every later reload.
     */
    public function remove(Application $application): ServerOpsResult
    {
        $context = ['feature' => 'application', 'op' => 'remove_config', 'application' => $application->id];

        $unregistered = $this->shared->unregister($this->fileName($application));

        if ($unregistered->failed()) {
            return $unregistered;
        }

        // Verify it actually went, rather than trusting a success that can be
        // vacuous: `unregister()` reports ok when nothing changed, and nothing
        // changes if the entries sit outside the managed markers — which is
        // what the WebAdmin console leaves behind, since the markers are
        // comments. Deleting the vhost file while the shared config still
        // names it breaks the config test for every site on the box.
        if ($this->shared->references($this->fileName($application))) {
            return $this->shared->test();
        }

        $directory = dirname($this->configPath($application));
        $root = rtrim((string) config('server.web_server_drivers.openlitespeed.vhost_root', '/usr/local/lsws/conf/vhosts'), '/');

        // `rm -rf` on a path built from a record is the most destructive
        // command in the panel. The slug is a slug by construction, but a
        // blank one would make this the
        // vhost root itself — deleting the configuration of every site on the
        // server. Refuse rather than rely on validation upstream staying put.
        abort_unless($directory !== $root && str_starts_with($directory, $root.'/'), 500);

        return $this->serverOps->run(['rm', '-rf', $directory], $context);
    }

    /**
     * @return array<int, string>
     */
    private function domains(Application $application): array
    {
        return array_values(array_unique([
            ...$application->serverNames(),
            ...$application->domains
                ->where('type', DomainType::Redirect)
                ->pluck('domain')
                ->all(),
        ]));
    }

    /**
     * @return array<int, string>
     */
    protected function testCommand(): array
    {
        return (array) config('server.web_server_drivers.openlitespeed.test_command');
    }

    /**
     * A restart, not a reload.
     *
     * `lswsctrl restart` is graceful — it starts new workers and lets the old
     * ones finish what they are serving. There is no cheaper option that picks
     * up a new virtual host, and on OLS a `.htaccess` or PHP configuration
     * change needs this too.
     *
     * @return array<int, string>
     */
    protected function reloadCommand(): array
    {
        return (array) config('server.web_server_drivers.openlitespeed.reload_command');
    }

    /**
     * OpenLiteSpeed already kept a site's logs inside the site's own directory
     * (`$VH_ROOT/logs` in the vhost template) rather than under /var/log —
     * this is the layout nginx and Apache have now been moved to, so all three
     * agree and `{@see Application::logsPath()}` is the only definition of it.
     *
     * The comment that used to sit here said these were "owned by the site's
     * system user, not root". That was true and is no longer: the directory is
     * root-owned with the site's group, so its owner can read the logs but not
     * replace a file a root process is appending to.
     * {@see ApplicationLogDirectory}
     *
     * @return array<string, string>
     */
    public function logPaths(Application $application): array
    {
        $dir = $application->logsPath();

        return [
            'access' => "{$dir}/access.log",
            'error' => "{$dir}/error.log",
        ];
    }
}
