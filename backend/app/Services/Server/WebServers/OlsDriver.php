<?php

namespace App\Services\Server\WebServers;

use App\Contracts\PhpStack;
use App\Enums\DomainType;
use App\Models\Application;
use App\Services\Server\Applications\ApplicationLogDirectory;
use App\Services\Server\Certificates\CertbotClient;
use App\Services\Server\Certificates\CertificateFiles;
use App\Services\Server\ManagedFile;
use App\Services\Server\Php\SitePhpIni;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\Waf\OlsWafRuleset;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * OpenLiteSpeed.
 *
 * Two things make it unlike the other two drivers, handled here rather than in the provisioner:
 *
 *  - **A site is a directory, not a file.** The config lives at `conf/vhosts/<name>/vhconf.conf`, so
 *    the directory has to exist first.
 *  - **A site is also two entries in the shared httpd_config.conf** (see `OlsSharedConfig`).
 *
 * The site name is the application slug (see AbstractWebServerDriver). It must be the same string in
 * three places (config directory, vhost name in httpd_config.conf, `map` entry), so it comes from one
 * `fileName()`.
 *
 * ⚠️ Unverified against a real OpenLiteSpeed server: paths, commands and directives come from
 * LiteSpeed's documentation and OLS's shipped config. Every path is in `config/server.php`, so a
 * wrong one is a config change, not a code change.
 */
class OlsDriver extends AbstractWebServerDriver
{
    public function __construct(
        ServerOps $serverOps,
        ManagedFile $files,
        CertificateFiles $certificateFiles,
        ApplicationLogDirectory $logDirectory,
        CertbotClient $certbot,
        private OlsSharedConfig $shared,
        private PhpStack $stack,
        private SitePhpIni $phpIni,
        private OlsConfigCheck $configCheck,
    ) {
        parent::__construct($serverOps, $files, $certificateFiles, $logDirectory, $certbot);
    }

    /**
     * How long the slug may be before the socket path stops being bindable.
     *
     * A unix socket path is capped by `sun_path` (108 bytes including the terminator). The prefix
     * `/tmp/lshttpd/lsphp84-` and `.sock` spend 26, a site name may be 255 characters, and 40 leaves
     * room for a three-digit LSPHP version.
     *
     * Truncation alone would make two long slugs with a shared prefix share a socket, so a hash of the
     * full slug goes on the end (for uniqueness, not secrecy).
     */
    private const SOCKET_NAME_LIMIT = 40;

    /**
     * Keyed on the slug, and short enough to bind.
     */
    private function socketName(Application $application): string
    {
        $name = $this->fileName($application);

        if (strlen($name) <= self::SOCKET_NAME_LIMIT) {
            return $name;
        }

        return substr($name, 0, self::SOCKET_NAME_LIMIT - 9).'-'.substr(md5($name), 0, 8);
    }

    /**
     * The template needs the interpreter itself, not a socket path: OLS starts the process, so the
     * vhost names the binary and the user (`lsphp84`, not `lsphp8.4`).
     *
     * @return array<string, mixed>
     */
    protected function viewData(Application $application, string $documentRoot): array
    {
        $data = parent::viewData($application, $documentRoot);
        $version = (string) $data['phpVersion'];

        $driver = 'server.web_server_drivers.openlitespeed';

        return [
            ...$data,
            // The 8G conditions for the categories this site has on; the
            // exceptions and custom rules are already in `$data['waf']`.
            'wafRules' => $data['waf'] === null ? [] : app(OlsWafRuleset::class)->for($data['waf']['categories']),
            // Site types whose cache or rewrite integration goes through .htaccess (WordPress: LiteSpeed
            // Cache talks to the OLS cache module through that file only).
            'readsHtaccess' => in_array(
                $application->site_type,
                (array) config('server.web_server_drivers.openlitespeed.htaccess_site_types', ['wordpress']),
                true,
            ),
            'lsphpVersion' => str_replace('.', '', $version),
            // The LSAPI socket's identity: slug, never domain. A domain is mutable and not unique, so two
            // applications on one domain shared a socket, one with no domain got `lsphp84-.sock`, and a
            // domain rename orphaned it. Every other per-site artifact is keyed by slug.
            'socketName' => $this->socketName($application),
            // The processor's name, which must be this site's alone. OLS keeps one external app per name
            // (logged as the name plus the vhost's suEXEC uid, `nobody` for every vhost), so with a shared
            // `lsphp84` the first vhost loaded supplied extUser and socket for all: sites' PHP ran as
            // another site's user, which could read their files.
            'processorName' => 'lsphp'.str_replace('.', '', $version).'-'.$this->socketName($application),
            // The LSAPI binary, not the CLI — this is what OLS spawns.
            'lsphpBinary' => $this->stack->handlerPath($version),
            // Where this site's own php settings live; written by `apply()` in the same pass as this
            // template, so the file and the `env` line naming it cannot drift apart.
            'phpIniScanDir' => $this->phpIni->scanDir($application),
            // One value, two directives. Floored at 1 because `maxConns 0` accepts no PHP at all.
            'lsapiChildren' => max(1, (int) config($driver.'.lsapi_children', 10)),
            'lsapiMaxRequests' => max(1, (int) config($driver.'.lsapi_max_requests', 5000)),
        ];
    }

    /**
     * Refuse to write a vhost that would run as nobody.
     *
     * `extUser` is the isolation boundary on this stack. Without it OpenLiteSpeed runs the site's PHP
     * as the server's own user (`nobody`), shared by every site, so each site could read every other
     * site's `.env`, database credentials and uploads while the vhost looks valid. php-fpm refuses a
     * pool with no `user =`; this is the equivalent backstop, first thing in apply(), ahead of every write.
     */
    private function assertHasSystemUser(Application $application): void
    {
        $username = trim((string) ($application->systemUser?->username ?? ''));

        if ($username === '') {
            throw new \RuntimeException(
                "application {$application->id} has no system user; refusing to write an OpenLiteSpeed vhost that would run as nobody"
            );
        }
    }

    /**
     * The 8G rules are rendered as rewrite directives inside each site's `vhconf.conf` (never
     * `.htaccess`, which OLS only reads after a restart), from the same file the Apache version uses;
     * see OlsWafRuleset.
     */
    public function supportsWaf(): bool
    {
        return true;
    }

    /**
     * An exception or custom rule for an *unquoted* OLS `RewriteCond`.
     * `preg_quote` makes it literal and also covers what a condition pattern would otherwise read as
     * an operator at its start (`!`, `<`, `>`, `=`, `-`). Whitespace would end the pattern and a quote
     * could start a quoted one, so those are hex escapes, which PCRE reads as the same characters.
     */
    public function wafPattern(string $value): string
    {
        return str_replace([' ', '"', "'"], ['\\x20', '\\x22', '\\x27'], preg_quote($value));
    }

    public function name(): string
    {
        return 'openlitespeed';
    }

    /**
     * OpenLiteSpeed already keeps v7's layout ({@see OlsVhostLayout}): one
     * site, its own files, no separate HTTPS file to add.
     */
    protected function splitsSsl(): bool
    {
        return false;
    }

    /**
     * Per-site directory, unlike the single file the other drivers write.
     *
     * Both halves come from the server, not config: a server migrated from the old panel has vhosts
     * under `/etc/<brand>-ols/` called `main.conf`, and writing this panel's path there would put the
     * config where the shared `httpd_config` does not point (a site that serves nothing). Writing where
     * the server already points also makes adoption cheap: the `virtualHost` blocks are already right.
     */
    public function configPath(Application $application): string
    {
        $layout = app(OlsVhostLayout::class);

        // Keyed by the application, as in AbstractWebServerDriver::configPath(); not by `vhRoot()`,
        // which is where the site's files live.
        return $layout->root()."/{$this->fileName($application)}/".$layout->filename();
    }

    /**
     * On a server laid out by v7 the site is also its wrapper: v7's `httpd_config.conf` loads
     * `/etc/<brand>-ols/*.conf`, each a `virtualhost` block that includes `<name>/main.conf`. Written
     * here so `sites:resync` keeps both files in step.
     *
     * @return array<string, string|null>
     */
    public function configFiles(Application $application, string $documentRoot): array
    {
        $files = parent::configFiles($application, $documentRoot);
        $wrapper = $this->legacyWrapperPath($application);

        if ($wrapper !== null) {
            $files[$wrapper] = $this->renderLegacyWrapper($application);
        }

        return $files;
    }

    /**
     * `/etc/<brand>-ols/<name>.conf`, or null on a server with this panel's
     * own layout.
     */
    public function legacyWrapperPath(Application $application): ?string
    {
        $layout = app(OlsVhostLayout::class);

        return $layout->isLegacy() ? $layout->root()."/{$this->fileName($application)}.conf" : null;
    }

    /**
     * v7's wrapper, now this panel's: one definition of the site, on v7's own listeners, loading the
     * vhost from `main.conf`.
     *
     * Domains and certificate stay in `main.conf` (one template for both layouts; the wrapper never
     * changes when a domain does). The site must not be defined twice, which writing this panel's own
     * `virtualHost` region beside v7's wrapper did, so on this layout the shared regions are never
     * written and the site attaches to the server's existing listeners (`Default`, `DefaultHttps`).
     */
    public function renderLegacyWrapper(Application $application): string
    {
        $layout = app(OlsVhostLayout::class);
        $name = $this->fileName($application);
        $listeners = (string) config('server.web_server_drivers.openlitespeed.legacy_listeners', 'Default,DefaultHttps');

        return implode("\n", [
            '# Managed by the panel. Manual edits are overwritten on the next deploy.',
            "virtualhost {$name} {",
            "    listeners               {$listeners}",
            '    vhRoot                  '.rtrim($this->vhRoot($application), '/').'/',
            '    allowSymbolLink         1',
            '    enableScript            1',
            '    restrained              1',
            '',
            "    include {$layout->root()}/{$name}/{$layout->filename()}",
            '}',
            '',
        ]);
    }

    /**
     * Directory, then vhost file, then the shared entries, in that order.
     *
     * The shared file goes last: it can take every site on the box down, so it is touched only once the
     * per-site config it points at is on disk (a `virtualHost` naming a missing file fails the config
     * test for the whole server).
     */
    public function apply(Application $application, string $documentRoot): ServerOpsResult
    {
        $this->assertHasSystemUser($application);

        $context = ['feature' => 'application', 'op' => 'write_config', 'application' => $application->id];

        // The layout decides where every file below goes; see SiteConfigResyncer.
        app(OlsVhostLayout::class)->detect();

        // Before anything is written: a site named like one of the server's own vhosts would overwrite
        // that vhost's `vhconf.conf` two steps below, before the shared config refuses it (a site named
        // `panel` took the panel's UI down).
        if ($this->shared->ownedByServer($this->fileName($application))) {
            $reference = (string) Str::uuid();

            Log::channel('server-ops')->error('ols vhost refused: name belongs to a vhost that is not a site', $context + [
                'reference' => $reference,
                'vhost' => $this->fileName($application),
            ]);

            return new ServerOpsResult(false, $reference);
        }

        $fallback = $this->ensureTlsFallback($application);

        if ($fallback->failed()) {
            return $fallback;
        }

        // Through the shared method, so this driver cannot drift from the base class's list and
        // `sites:resync` (which writes a vhost without apply()) gets the same preparation. It matters
        // here: OLS resolves a context's `location` at load time, so the ACME context's directory must
        // already exist.
        $this->ensureDirectories($application);

        // The site's own PHP settings, before the vhost that points LSPHP at them. On php-fpm they ride
        // in the pool file; there is no pool here, so every setting on the PHP screen saved and changed
        // nothing. Not fatal: a site without its overrides still serves on the stack's defaults.
        if ($application->serving_profile === 'php') {
            $this->phpIni->apply($application);
        }

        $directory = $this->serverOps->run(
            ['mkdir', '-p', dirname($this->configPath($application))],
            $context,
        );

        if ($directory->failed()) {
            return $directory;
        }

        $written = $this->files->put(
            $this->configPath($application),
            // The vhost alone: its rule files go to the conf folder below.
            $this->renderSection($application, $documentRoot, null),
            $context,
        );

        if ($written->failed()) {
            return $written;
        }

        foreach ($this->siteConfFiles($application, $documentRoot) as $path => $contents) {
            $rules = $this->writeSiteConf($path, $contents, $context + ['op' => 'write_site_conf']);

            if ($rules->failed()) {
                return $rules;
            }
        }

        $wrapper = $this->legacyWrapperPath($application);

        if ($wrapper !== null) {
            // After main.conf, which it includes: a wrapper naming a file that
            // is not there fails the config test for every site on the box.
            $wrapped = $this->files->put($wrapper, $this->renderLegacyWrapper($application), $context);

            if ($wrapped->failed()) {
                return $wrapped;
            }

            // Drop an entry this panel wrote for the site before, so it is
            // defined once. Only when there is one: the shared file is every
            // site's, and rewriting it for nothing is a risk with no purpose.
            return $this->shared->references($this->fileName($application))
                ? $this->shared->unregister($this->fileName($application))
                : $wrapped;
        }

        // The vhost NAME in the shared config must agree with the directory `configPath()` writes into
        // (the application, not a domain); the domains it answers to are the second argument.
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
        // Slug, not domain: `restrained 1` confines the vhost to this directory and the document root is
        // `{root}/public_html/…`, so a domain-named root put the document root outside the restraint and
        // moved the logs whenever a domain changed.
        return $application->rootPath();
    }

    /**
     * Shared entries first this time, then the files: the mirror image of `apply()`. Stop OLS referring
     * to the site before removing what it refers to, or a `virtualHost` points at a deleted file, which
     * fails the config test and blocks every later reload.
     */
    public function remove(Application $application): ServerOpsResult
    {
        $context = ['feature' => 'application', 'op' => 'remove_config', 'application' => $application->id];

        $unregistered = $this->shared->unregister($this->fileName($application));

        if ($unregistered->failed()) {
            return $unregistered;
        }

        // Verify it actually went: `unregister()` reports ok when nothing changed, which happens when the
        // entries sit outside the managed markers (as the WebAdmin console leaves them). Deleting the
        // vhost file while the shared config still names it breaks the config test for every site.
        if ($this->shared->references($this->fileName($application))) {
            return $this->shared->test();
        }

        // The wrapper before the directory it includes, for the same reason.
        $wrapper = $this->legacyWrapperPath($application);

        if ($wrapper !== null) {
            $gone = $this->files->delete($wrapper, $context);

            if ($gone->failed()) {
                return $gone;
            }
        }

        $directory = dirname($this->configPath($application));

        // The same root `configPath()` just used: the configured one made the guard below compare two
        // layouts on a migrated server (the directory under `/etc/<brand>-ols/` is never beneath it),
        // so every delete aborted.
        $root = rtrim(app(OlsVhostLayout::class)->root(), '/');

        // `rm -rf` on a path built from a record is the most destructive command in the panel. A blank
        // slug would make this the vhost root itself, deleting every site's configuration: refuse
        // rather than rely on upstream validation.
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
     * Through `OlsConfigCheck`, which owns the reasons and the preparation the raw command needs
     * (shared with `OlsSharedConfig`).
     */
    public function test(): ServerOpsResult
    {
        return $this->configCheck->run([
            'op' => 'config_test',
            'web_server' => $this->name(),
        ]);
    }

    /**
     * A restart, not a reload: `lswsctrl restart` is graceful (new workers start, old ones finish what
     * they serve). There is no cheaper way to pick up a new virtual host, and a `.htaccess` or PHP
     * configuration change needs it too.
     *
     * @return array<int, string>
     */
    protected function reloadCommand(): array
    {
        return (array) config('server.web_server_drivers.openlitespeed.reload_command');
    }

    /**
     * A site's logs live inside the site's own directory (`$VH_ROOT/logs` in the vhost template), as
     * on nginx and Apache, so {@see Application::logsPath()} is the only definition. The directory is
     * root-owned with the site's group: the owner can read the logs but not replace a file a root
     * process is appending to. {@see ApplicationLogDirectory}
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

    /**
     * The account OpenLiteSpeed's workers run as (they write the vhost logs as it), read from
     * httpd_config.conf rather than assumed: `nobody` on a stock install, never `www-data`.
     */
    public function siteReaderUser(): ?string
    {
        return $this->logWriterUser();
    }

    /**
     * OpenLiteSpeed's workers open the vhost's own access and error logs, so the account they run as
     * has to be able to reach the directory.
     *
     * Asked of `httpd_config.conf`, not `server.web_server_user`: that defaults to `www-data`, install.sh
     * never writes it and lsws runs as `nobody`, so granting the configured account would grant nothing
     * useful while looking like it worked. Reading belongs to {@see OlsSharedConfig}; only the fallback
     * is decided here (`nobody`, what a stock install runs as, is no wider than the grant being made).
     */
    public function logWriterUser(): ?string
    {
        return $this->shared->serverUser()
            ?? (string) config('server.web_server_drivers.openlitespeed.user', 'nobody');
    }

    /** v7 keeps OpenLiteSpeed's in conf/openlitespeed/rewrites/, inside `rewrite {}`. */
    protected function siteConfRulesDir(Application $application): string
    {
        return $application->siteConfPath().'/rewrites';
    }
}
