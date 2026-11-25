<?php

namespace App\Services\Server\WebServers;

use App\Contracts\WebServerDriver;
use App\Enums\AiBotPolicy;
use App\Enums\CertificateType;
use App\Enums\DomainType;
use App\Enums\WafMode;
use App\Models\Application;
use App\Models\ApplicationPhpSettings;
use App\Models\ApplicationWafRule;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\Applications\ApplicationLogDirectory;
use App\Services\Server\Applications\PanelDirectory;
use App\Services\Server\Applications\SiteRootLock;
use App\Services\Server\Certificates\CertbotClient;
use App\Services\Server\Certificates\CertificateFiles;
use App\Services\Server\ManagedFile;
use App\Services\Server\Php\PoolManager;
use App\Services\Server\ServerAddresses;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\View;

abstract class AbstractWebServerDriver implements WebServerDriver
{
    public function __construct(
        protected ServerOps $serverOps,
        protected ManagedFile $files,
        protected CertificateFiles $certificateFiles,
        protected ApplicationLogDirectory $logDirectory,
        protected CertbotClient $certbot,
    ) {}

    /**
     * The site's files in sites-available, each symlinked from sites-enabled (the layout install.sh
     * uses for the panel's own vhost). A driver whose configuration is not such a pair
     * (OpenLiteSpeed) overrides this.
     *
     * Two files on nginx and Apache, as v7 writes them ({@see configFiles()}). A file that should not
     * exist is removed, link first, so the web server never reads a link to nothing.
     */
    public function apply(Application $application, string $documentRoot): ServerOpsResult
    {
        $fallback = $this->ensureTlsFallback($application);

        if ($fallback->failed()) {
            return $fallback;
        }

        $this->ensureDirectories($application);

        $context = ['feature' => 'application', 'application' => $application->id];
        $result = new ServerOpsResult(true, 'config-applied');

        foreach ($this->configFiles($application, $documentRoot) as $path => $contents) {
            if ($this->isSiteConfPath($application, $path)) {
                $written = $this->writeSiteConf($path, $contents, $context + ['op' => 'write_site_conf']);

                if ($written->failed()) {
                    return $written;
                }

                continue;
            }

            if ($contents === null) {
                $this->files->delete($this->enabledPathFor($path), $context + ['op' => 'remove_config']);
                $this->files->delete($path, $context + ['op' => 'remove_config']);

                continue;
            }

            $written = $this->files->put($path, $contents, $context + ['op' => 'write_config']);

            if ($written->failed()) {
                return $written;
            }

            $result = $this->link($path, $context + ['op' => 'enable_config']);

            if ($result->failed()) {
                return $result;
            }
        }

        return $result;
    }

    /**
     * Every config file this site has, by path in sites-available, with what it should hold — or null
     * for a file that must not exist.
     *
     * v7's layout: `{name}.conf` for plain HTTP and a second file for HTTPS (`{name}-le-ssl.conf`
     * with a Let's Encrypt certificate, `{name}-ssl.conf` with any other). Writing the same two
     * names replaces v7's in place; one file for everything would leave v7's SSL file beside it,
     * two server blocks for one name on 443. A driver that does not split (OpenLiteSpeed) has one
     * file.
     *
     * @return array<string, ?string>
     */
    public function configFiles(Application $application, string $documentRoot): array
    {
        if (! $this->splitsSsl()) {
            return [$this->configPath($application) => $this->renderSection($application, $documentRoot, null)]
                + $this->siteConfFiles($application, $documentRoot);
        }

        $files = [$this->configPath($application) => $this->renderSection($application, $documentRoot, 'main')];
        $active = $this->activeSslPath($application);

        foreach ($this->sslConfigPaths($application) as $path) {
            $files[$path] = $path === $active ? $this->renderSection($application, $documentRoot, 'ssl') : null;
        }

        return $files + $this->siteConfFiles($application, $documentRoot);
    }

    /**
     * The site's bot blocker, 8G firewall and (Apache) force HTTPS, each in v7's own file in the
     * site's conf folder, which the vhost includes as v7's does. Null for one that is off: the
     * panel's own copy is then removed, v7's or the owner's never ({@see self::writeSiteConf()};
     * {@see V7Leftovers} moves v7's aside when the owner changes that setting).
     *
     * @return array<string, ?string>
     */
    public function siteConfFiles(Application $application, string $documentRoot): array
    {
        $rules = array_filter(
            $this->siteConfRules(),
            fn (array $rule) => View::exists("server.vhosts.{$this->name()}.{$rule[0]}"),
        );

        if ($rules === []) {
            return [];
        }

        $data = $this->viewData($application, $documentRoot);
        $directory = $this->siteConfRulesDir($application);
        $files = [];

        foreach ($rules as $file => [$view, $key]) {
            $files["{$directory}/{$file}"] = ! empty($data[$key])
                ? View::make("server.vhosts.{$this->name()}.{$view}", $data)->render()
                : null;
        }

        return $files;
    }

    /**
     * File name => [view, the view-data key that turns it on], v7's names.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    protected function siteConfRules(): array
    {
        return [
            '8g-firewall.conf' => ['_8g-firewall', 'waf'],
            'ai-bot-blocker.conf' => ['_ai-bot-blocker', 'botBlock'],
        ];
    }

    /** Where those files go: the conf folder itself on nginx and Apache. */
    protected function siteConfRulesDir(Application $application): string
    {
        return $application->siteConfPath();
    }

    /** Whether a path from configFiles() is one of the site's conf-folder files. */
    public function isSiteConfPath(Application $application, string $path): bool
    {
        return str_starts_with($path, rtrim($application->siteConfPath(), '/').'/');
    }

    /**
     * Whether a conf-folder file's text is the panel's to replace: its own
     * marker, or nothing at all (an empty file holds nothing to keep).
     */
    public static function siteConfIsOurs(string $contents): bool
    {
        return trim($contents) === '' || str_starts_with($contents, SharedConfigFile::MARKER);
    }

    /**
     * Write or remove one of the site's conf-folder files, never one the panel did not write. Those
     * names are v7's, so on a site from v7 the file is v7's own (or its owner's) and stays as it is.
     *
     * @param  array<string, mixed>  $context
     */
    public function writeSiteConf(string $path, ?string $contents, array $context): ServerOpsResult
    {
        $current = $this->files->get($path, $context);
        $exists = $current->ok;

        if ($exists && ! self::siteConfIsOurs($current->output())) {
            return new ServerOpsResult(true, 'site-conf-not-ours');
        }

        if ($contents === null) {
            return $exists ? $this->files->delete($path, $context) : new ServerOpsResult(true, 'site-conf-absent');
        }

        if ($exists && $current->output() === $contents) {
            return new ServerOpsResult(true, 'site-conf-current');
        }

        return $this->files->put($path, $contents, $context);
    }

    /**
     * Both names the HTTPS file can have (v7's), so the one not in use can be removed when the
     * certificate changes type.
     *
     * @return array{0: string, 1: string} [Let's Encrypt, any other]
     */
    public function sslConfigPaths(Application $application): array
    {
        $directory = rtrim((string) config("server.web_server_drivers.{$this->name()}.sites_available_dir"), '/');
        $name = $this->fileName($application);

        return ["{$directory}/{$name}-le-ssl.conf", "{$directory}/{$name}-ssl.conf"];
    }

    /**
     * The sites-enabled link for a file in sites-available.
     */
    public function enabledPathFor(string $availablePath): string
    {
        $directory = rtrim((string) config("server.web_server_drivers.{$this->name()}.sites_dir"), '/');

        return $directory.'/'.basename($availablePath);
    }

    /**
     * Make a written file live. A symlink from sites-enabled on nginx and
     * Apache; nothing to do for a driver that does not split.
     *
     * @param  array<string, mixed>  $context
     */
    public function link(string $availablePath, array $context = []): ServerOpsResult
    {
        // Only a vhost in sites-available is linked; a site's conf-folder file
        // is included by the vhost.
        $available = rtrim((string) config("server.web_server_drivers.{$this->name()}.sites_available_dir"), '/');

        if (! $this->splitsSsl() || dirname($availablePath) !== $available) {
            return new ServerOpsResult(true, 'no-link-required');
        }

        return $this->files->symlink($availablePath, $this->enabledPathFor($availablePath), $context);
    }

    /**
     * Whether this web server keeps a site's HTTPS in a file of its own (v7's
     * nginx and Apache layout).
     */
    protected function splitsSsl(): bool
    {
        return true;
    }

    private function activeSslPath(Application $application): ?string
    {
        $certificate = $application->certificate;

        if (! $certificate?->servable()) {
            return null;
        }

        [$letsEncrypt, $other] = $this->sslConfigPaths($application);

        return $certificate->type === CertificateType::LetsEncrypt ? $letsEncrypt : $other;
    }

    /**
     * A no-certificate site must still own its hostname on port 443. Browsers retain HSTS and
     * HTTPS-first state after uninstall; without a reject vhost the request falls through to another
     * application's default TLS vhost. Apache and OpenLiteSpeed need a harmless shared key pair to
     * reject it, and so does an nginx too old to refuse the handshake
     * ({@see NginxDriver::ensureTlsFallback()}).
     */
    protected function ensureTlsFallback(Application $application): ServerOpsResult
    {
        if ($application->certificate?->servable()) {
            return new ServerOpsResult(true, 'tls-fallback-not-required');
        }

        return $this->certificateFiles->ensureFallback();
    }

    /**
     * The shared ACME webroot, before a vhost that names it goes on disk.
     *
     * Every template declares a location for `{challenge_root}/.well-known/acme-challenge`, which
     * only `IssueCertificate` used to create. nginx and Apache resolve a location per request, but
     * **OpenLiteSpeed resolves a context's location when the configuration is loaded**, so a
     * missing directory fails the config (install.sh's `ensure_ols_context_paths()` does the same).
     *
     * Cheap and idempotent (`install -d`), and deliberately unchecked: this must not be able to
     * stop a site being written. A challenge root that cannot be created is certbot's problem to
     * report at issuance.
     */
    protected function ensureChallengeRoot(): void
    {
        $this->certbot->ensureChallengeRoot();
    }

    /**
     * Every directory the rendered config is about to name.
     *
     * Public, and called by anything that writes a vhost, not only `apply()`: `sites:resync` writes
     * the same file directly to skip unchanged sites. On OpenLiteSpeed a context's `location` is
     * resolved when the config loads, so a resync naming a challenge root nothing had created
     * failed the config test and rolled that site back.
     *
     * All best-effort, as `apply()` always treated them: a directory that cannot be created is
     * reported by whatever needs it, not by refusing to write a site's configuration.
     */
    public function ensureDirectories(Application $application): bool
    {
        $this->ensurePanelDirectory($application);
        $this->ensureChallengeRoot();
        $this->ensureSiteRules($application);
        app(SiteConfFolder::class)->ensure($application, $this->name());

        // The vhost names `logs/access.log`, and a web server refuses to start when a log file's
        // directory does not exist, which would fail `nginx -t` on every site and read as a bad
        // template.
        //
        // The only step here whose effect a running web server cannot see until it restarts, so the
        // only one that answers; the two above create directories that every later config test
        // reads fresh.
        return $this->logDirectory->ensure($application);
    }

    /**
     * nginx and Apache workers run as the account PHP-FPM pool sockets are already handed to
     * ({@see PoolManager}): `www-data` on the Debian packages install.sh uses. The same setting, so the
     * two cannot name different accounts.
     */
    public function siteReaderUser(): ?string
    {
        $user = (string) config('server.web_server_user', 'www-data');

        return $user === '' ? null : $user;
    }

    /**
     * Nothing, by default: nginx and Apache open a site's access log in the root master process and
     * hand the descriptor to a worker, so no unprivileged account ever needs the log directory.
     * Stated here so a future web server inherits the answer that grants nothing.
     */
    public function logWriterUser(): ?string
    {
        return null;
    }

    public function catchAllConfig(): ?array
    {
        $key = "server.web_server_drivers.{$this->name()}";
        $paths = array_filter([
            'main' => (string) config("{$key}.catch_all"),
            'ssl' => (string) config("{$key}.catch_all_ssl"),
        ]);

        if ($paths === [] || ! View::exists("server.vhosts.{$this->name()}._catch-all")) {
            return null;
        }

        $enabledDir = rtrim((string) config("{$key}.catch_all_enabled_dir"), '/');
        $files = [];
        $links = [];

        foreach ($paths as $section => $path) {
            $files[$path] = View::make("server.vhosts.{$this->name()}._catch-all", $this->catchAllViewData() + ['section' => $section])->render();

            if ($enabledDir !== '') {
                $links[$enabledDir.'/'.basename($path)] = $path;
            }
        }

        return [
            'files' => $files,
            'links' => $links,
            'legacy' => array_values(array_map('strval', (array) config("{$key}.catch_all_legacy", []))),
            'package' => config("{$key}.catch_all_package"),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function catchAllViewData(): array
    {
        return ['tlsFallback' => $this->certificateFiles->fallbackPaths()];
    }

    /**
     * The rendered config can name files inside `.panel/` (the WAF detect log, the Basic Auth
     * credential), and nginx refuses to start when a log directory does not exist. Without this,
     * turning WAF detect mode on for a site that never had its web root moved wrote a config that
     * could not pass `nginx -t`.
     */
    protected function ensurePanelDirectory(Application $application): void
    {
        // `.panel` is an entry of the site root, which is immutable once the site is set up
        // ({@see SiteRootLock}): creating it on a site that never had one needs the flag lifted. Resolved
        // here rather than injected so every driver's constructor does not have to learn of it.
        app(SiteRootLock::class)->ensureDirectory(
            $application,
            $application->panelPath(),
            ['feature' => 'application', 'op' => 'ensure_panel_dir', 'application' => $application->id],
        );

        // And root's again, with anything planted in it removed. `sites:resync` comes through here on
        // every deploy, which is how servers where `.panel` was handed to the site user repair
        // themselves. See PanelDirectory::secure().
        app(PanelDirectory::class)->secure($application);
    }

    /**
     * The site's extra-rules directory, which its vhost includes.
     *
     * Created before the vhost is written because the include must not point at nothing on
     * OpenLiteSpeed. Root's, 0755, and never emptied here: the files inside are an addon's. Removed
     * with the site by ApplicationArtifacts, never by `remove()`, which also runs for a resync's
     * legacy-config cleanup and its rollback, neither of which may take a site's rules with it.
     * {@see config('server.site_rules_root')}
     */
    protected function ensureSiteRules(Application $application): void
    {
        $context = ['feature' => 'application', 'op' => 'ensure_site_rules', 'application' => $application->id];

        $this->serverOps->run(['mkdir', '-p', $application->siteRulesPath()], $context);
        $this->serverOps->run(['chmod', '0755', $application->siteRulesPath()], $context);
    }

    /**
     * Removes both the sites-enabled symlink and the sites-available file it pointed to, so a
     * re-provision starts from nothing. Both calls are idempotent (`rm -f`), so this runs
     * unconditionally rather than stopping at the first failure.
     */
    public function remove(Application $application): ServerOpsResult
    {
        $context = ['feature' => 'application', 'op' => 'remove_config', 'application' => $application->id];
        $first = null;
        $last = new ServerOpsResult(true, 'nothing-to-remove');

        // Every file the site can have — the HTTPS file under either of its
        // names too — each link before its file.
        foreach ([$this->configPath($application), ...($this->splitsSsl() ? $this->sslConfigPaths($application) : [])] as $path) {
            foreach ([$this->enabledPathFor($path), $path] as $target) {
                $result = $this->files->delete($target, $context);
                $first ??= $result->failed() ? $result : null;
                $last = $result;
            }
        }

        return $first ?? $last;
    }

    /**
     * The real file — sites-available. This is what gets rendered, tested via
     * its symlink, and what disable()/enable() overwrite the contents of.
     */
    public function configPath(Application $application): string
    {
        $directory = rtrim((string) config("server.web_server_drivers.{$this->name()}.sites_available_dir"), '/');

        // Named after the application, not its domain: a domain is mutable and was never unique, so
        // two sites could overwrite each other's vhost and a domain change orphaned the old file.
        // The slug is unique and a filename by construction.
        return "{$directory}/{$this->fileName($application)}.conf";
    }

    /**
     * The symlink — sites-enabled. This is what the web server actually reads
     * via its `include sites-enabled/*` directive; configPath() alone is
     * invisible to it until this exists.
     */
    protected function enabledPath(Application $application): string
    {
        $directory = rtrim((string) config("server.web_server_drivers.{$this->name()}.sites_dir"), '/');

        return "{$directory}/{$this->fileName($application)}.conf";
    }

    /**
     * Falls back to the domain for a row that predates the slug column, so a
     * site provisioned before this still resolves to the file it actually has
     * on disk rather than to a name nothing was ever written under.
     */
    protected function fileName(Application $application): string
    {
        return (string) ($application->slug ?: $application->domain);
    }

    /**
     * Serving profiles that share a vhost, and which one they share.
     *
     * A container and a Node application are the same thing to a web server: something on a
     * loopback port that nginx reverse-proxies to (WebSocket upgrade included), and the `node`
     * template never asks what is behind it. A map rather than a second template that includes the
     * other, which would be a second file to keep in step. Templates are selected by profile NAME,
     * so without this a container aborts with a bare 500 from `View::exists`.
     *
     * @var array<string, string>
     */
    private const VHOST_PROFILE_ALIASES = [
        'docker' => 'node',
    ];

    /**
     * Everything the site's files hold, in one string — what tests and any
     * reader that wants "the site's configuration" look at. Written to disk
     * it is {@see configFiles()}, one file per part.
     */
    public function renderConfig(Application $application, string $documentRoot): string
    {
        // Every file the site's config is made of — its vhost (two, on the
        // split layouts) and the rule files in its conf folder — in one text.
        return implode("\n", array_filter($this->configFiles($application, $documentRoot), fn (?string $contents) => $contents !== null));
    }

    /**
     * One file's worth: `main` (plain HTTP, and the no-certificate reject on
     * 443) or `ssl` (HTTPS). Null for a template that is not split.
     */
    protected function renderSection(Application $application, string $documentRoot, ?string $section): string
    {
        $profile = (string) $application->serving_profile;
        $profile = self::VHOST_PROFILE_ALIASES[$profile] ?? $profile;

        $view = "server.vhosts.{$this->name()}.{$profile}";

        abort_unless(View::exists($view), 500);

        return View::make($view, $this->viewData($application, $documentRoot) + ['section' => $section])->render();
    }

    /**
     * The site's `post_max_size` in bytes, as the web server should enforce it.
     *
     * Reads the site's own settings where it has them and the shared defaults where it does not: the
     * same `effective()` the pool file is rendered from, so web server and PHP cannot disagree.
     *
     * A floor of 1 MB: `post_max_size = 0` is legal PHP and means "no limit", but while nginx and
     * Apache's `LimitRequestBody` read 0 as unlimited, OpenLiteSpeed reads it as *reject every
     * body*. A real number goes to all three.
     */
    protected function maxBodySizeBytes(Application $application): int
    {
        $settings = $application->phpSettings ?? new ApplicationPhpSettings;

        $bytes = ApplicationPhpSettings::toBytes((string) $settings->effective()['post_max_size']);

        return max($bytes, 1024 * 1024);
    }

    /**
     * What a vhost template is given. A driver whose syntax needs more than
     * this adds to it.
     *
     * @return array<string, mixed>
     */
    protected function viewData(Application $application, string $documentRoot): array
    {
        $siteType = app(SiteTypeManager::class)->find((string) $application->site_type);

        return [
            'application' => $application,
            'domain' => $application->domain,
            'canonicalUrl' => $application->url(),
            // Log filenames, not server_name: named after the app like the vhost and pool files (see
            // fileName()), so a domain change doesn't orphan the log history.
            'logName' => $this->fileName($application),
            // Handed to the template rather than built inside it, so the vhost and `logPaths()`
            // cannot drift (fail2ban and the Logs screen would point at a file nothing writes).
            // {@see Application::logsPath()}
            'logDir' => $application->logsPath(),
            // Every name the site answers to, primary first (a row in application_domains,
            // backfilled for existing sites; templates used to hardcode `www.{domain}`).
            'serverNames' => $application->serverNames(),
            // Redirects get their own server block: they serve nothing, and mixing them into the main
            // block would serve the same content under both names.
            'redirects' => $application->domains
                ->filter(fn ($domain) => $domain->type === DomainType::Redirect)
                ->values(),
            // Only a certificate with files behind it: a path that is not there fails the config test
            // and takes a working site down over a certificate it never had.
            'certificate' => $application->certificate?->servable() ? $application->certificate : null,
            // Never borrow a real site's certificate when a no-certificate hostname reaches 443.
            // Apache and OLS need a key pair to reject the request; this reserved-name pair
            // identifies no user application and is generated lazily on brownfield boxes.
            'tlsFallback' => $this->certificateFiles->fallbackPaths(),
            // A disabled site's vhost serves the shared "unavailable" page as 503 (a 200 told
            // monitors and crawlers the site was up).
            'disabled' => $application->disabled_at !== null,
            // Paths the site type ships `.htaccess` deny rules for: Apache reads those itself,
            // nginx and OpenLiteSpeed render these.
            'deniedPaths' => $siteType?->deniedPaths() ?? [],
            // Directories with their own front controller, which Apache gets
            // from the application's own `.htaccess`.
            'subdirectoryFrontControllers' => $siteType?->subdirectoryFrontControllers() ?? [],
            // /.well-known routing and file types the application sets in its
            // own .htaccess — Nextcloud's CalDAV/CardDAV discovery and .mjs.
            'wellKnown' => $siteType?->wellKnownRoutes() ?? ['redirects' => [], 'fallback' => null],
            'mimeTypes' => $siteType?->mimeTypes() ?? [],
            // Password protection forces it too: with the redirect off, plain http asked for the
            // site password and took it in clear. Nothing to redirect to without a certificate, so
            // an http-only site keeps working, and the API says it is unencrypted
            // (`basic_auth_unencrypted`).
            'forceHttps' => $forceHttps = $application->scheme() === 'https'
                && ($application->certificate?->force_https || $application->basic_auth_enabled),
            // Names the certificate does not cover, while HTTPS is forced: https://<that name>
            // would land the visitor on a certificate error, so they go to the primary instead
            // (always covered when forcing). Every covered name keeps its own host, as on v7.
            'uncoveredNames' => $forceHttps
                ? array_values(array_filter(
                    $application->serverNames(),
                    fn (string $name): bool => ! $application->certificate->covers($name),
                ))
                : [],
            // The shared ACME webroot, aliased into every profile: node and proxy sites serve
            // nothing from disk, so a per-site root has nowhere for certbot to drop the challenge
            // token.
            'challengeRoot' => rtrim((string) config('server.certificates.challenge_root'), '/'),
            'documentRoot' => $documentRoot,
            // Included by the vhost, never rendered into it — see ensureSiteRules().
            'siteRules' => $application->siteRulesPath(),
            // v7's per-site folder, loaded where v7 loaded it ({@see SiteConfFolder}).
            'siteConf' => $application->siteConfPath(),
            // The largest request body this site accepts, in bytes. Every web server has its own
            // default (nginx: 1 MB), so a site whose PHP said `upload_max_filesize = 64M` still got
            // "413 Request Entity Too Large": nginx rejects before PHP is reached.
            //
            // Derived from `post_max_size`, not `upload_max_filesize`: that is the web servers' own
            // measurement (the whole body, multipart boundaries and other fields included).
            // Resolved here so all three templates read one number.
            'maxBodySize' => $this->maxBodySizeBytes($application),
            // v7 gave PHP 300 s at the web server; longer when the site's own
            // max_execution_time is longer, so nginx never cuts PHP off first.
            'fastcgiReadTimeout' => max(300, (int) (($application->phpSettings ?? new ApplicationPhpSettings)->effective()['max_execution_time'] ?? 0) + 30),
            'phpVersion' => $application->php_version ?: config('server.default_php_version'),
            // Where PHP actually is for this site: an isolated site has its own pool running as its
            // own user, everything else shares the server-wide pool. Resolved here so no template
            // has to know the rule.
            'phpSocket' => app(PoolManager::class)->socketFor($application),
            // The OS account the site runs as. nginx and Apache reach PHP through a pool that already
            // knows this; OLS spawns the process itself and has to be told.
            'user' => $application->systemUser?->username,
            // Where a reverse proxy sends traffic, and a backend name unique per application
            // (OpenLiteSpeed declares external applications by name, and two sites sharing one would
            // overwrite each other).
            'appPort' => $application->app_port,
            'appName' => 'sv-app-'.$application->id,
            // Null when off, so every template gates the whole block behind one `@if`. The path
            // comes from the model so this copy and BasicAuthManager's cannot disagree.
            'basicAuth' => $application->basic_auth_enabled ? [
                'realm' => 'sv-app-'.$application->id,
                'htpasswdPath' => $application->basicAuthPath(),
                // Paths the application requests from itself (WordPress's wp-cron.php), let through
                // without a password only from the server's own addresses; null when the type has
                // none.
                'serverOnly' => ($serverOnly = $siteType?->serverOnlyPaths() ?? []) === [] ? null : [
                    'paths' => $serverOnly,
                    'addresses' => app(ServerAddresses::class)->local(),
                ],
            ] : null,
            // A single escaped, alternation-joined regex string, or null when the policy blocks
            // nothing. Resolved here so no template re-implements the policy against
            // `config/ai_bots.php`.
            'botBlock' => $this->botBlockPattern($application),
            'waf' => $this->wafViewData($application, $documentRoot),
        ];
    }

    /**
     * Whether this driver can actually enforce the 8G Firewall.
     *
     * Asked rather than assumed: the shared ruleset writer used to fall through silently for anything
     * but nginx and apache, and no OpenLiteSpeed template referenced the rules, so the API said
     * `waf_enabled: true` while no request was inspected.
     */
    public function supportsWaf(): bool
    {
        return true;
    }

    /**
     * A WAF exception or custom rule as a case-insensitive *literal* match,
     * ready to sit inside a double-quoted regex in this web server's config.
     *
     * nginx: `preg_quote` makes it literal; nginx's quoted strings then turn
     * `\\` into `\` and `\"` into `"`, so a backslash the user typed is
     * doubled and a quote escaped. Control characters never get here — the
     * request refuses them.
     */
    public function wafPattern(string $value): string
    {
        return str_replace(['\\\\', '"'], ['\\\\\\\\', '\\"'], preg_quote($value));
    }

    /**
     * Null unless the firewall is on and actually has something to check: with zero categories and
     * zero custom rules nothing would be blocked, so the template renders no block rather than an
     * empty `<RequireAny>`/`if` chain.
     *
     * @return array{mode: string, categories: array<int, string>, exceptions: array<int, string>, customRules: array<int, string>, detectLogPath: string, logFormat: string}|null
     */
    private function wafViewData(Application $application, string $documentRoot): ?array
    {
        if (! $application->waf_enabled) {
            return null;
        }

        $categories = $application->wafActiveCategories();
        // `wafRules` lazy-loads from the database if not already set, so a caller can pre-set it in
        // memory (see Waf8GManager::apply()) to render not-yet-saved rules without a second query
        // overwriting them.
        $customRules = $application->wafRules->where('type', 'block')->pluck('value')->all();

        if ($categories === [] && $customRules === []) {
            return null;
        }

        // Short ones are skipped, not just refused on save: one stored before
        // the limit would otherwise go on switching the firewall off.
        $exceptions = $application->wafRules->where('type', 'exception')->pluck('value')
            ->filter(fn (string $value): bool => mb_strlen(trim($value)) >= ApplicationWafRule::EXCEPTION_MIN_LENGTH)
            ->values()->all();

        return [
            'mode' => $application->waf_mode instanceof WafMode ? $application->waf_mode->value : (string) $application->waf_mode,
            'categories' => $categories,
            // Escaped here, for this web server's config syntax, and printed raw by the templates:
            // Blade's `{{ }}` HTML-encodes (`page=1&x` became `page=1&amp;x` and never matched).
            'exceptions' => array_map(fn (string $value): string => $this->wafPattern($value), $exceptions),
            'customRules' => array_map(fn (string $value): string => $this->wafPattern($value), $customRules),
            'detectLogPath' => $application->wafDetectLogPath(),
            // nginx: the name of the site's own log_format for that file. A
            // name is server-wide in nginx, so it carries the site's id.
            'logFormat' => 'panel_waf_'.$application->id,
        ];
    }

    /**
     * The policy's built-in list, plus this site's own additions, minus its own exemptions.
     *
     * Exemptions are subtracted last and case-insensitively, so "allow this one" wins over both the
     * built-in list and a contradictory custom block.
     *
     * `botRules` lazy-loads if not already set, so a caller can pre-set it in memory (see
     * BotBlockerManager::apply()) to render not-yet-saved rules.
     */
    private function botBlockPattern(Application $application): ?string
    {
        $bots = $application->ai_bot_policy instanceof AiBotPolicy
            ? $application->ai_bot_policy->blockedBots()
            : [];

        $rules = $application->botRules;

        $bots = array_merge($bots, $rules->where('type', 'block')->pluck('value')->all());

        $allowed = $rules->where('type', 'allow')
            ->map(fn ($rule) => mb_strtolower((string) $rule->value))
            ->all();

        $bots = array_values(array_filter(
            array_unique($bots),
            fn (string $bot) => ! in_array(mb_strtolower($bot), $allowed, true),
        ));

        if ($bots === []) {
            return null;
        }

        // A space is written as \x20: OpenLiteSpeed's RewriteCond takes the pattern unquoted, so a
        // literal space ends it and the config test fails (23 of v7's names have one, e.g. "Hugging
        // Face").
        $pattern = implode('|', array_map(fn (string $bot) => str_replace(' ', '\x20', preg_quote($bot, '/')), $bots));

        if ($application->ai_bot_policy !== AiBotPolicy::LegacyV7) {
            return $pattern;
        }

        // v7's list has short tokens that sit inside other names ("GPT" is in "GPTBot"), so leaving
        // an allowed name out does not let it through. On legacy_v7 an allowed name exempts the
        // whole user-agent, as YandexBot always has. Allowed names cannot hold a space.
        $exempt = implode('|', ['YandexBot', ...array_map(fn (string $bot) => preg_quote($bot, '/'), $allowed)]);

        // Anchor the exemption to the whole user-agent: an unanchored negative
        // lookahead could retry after YandexBot and wrongly block a later token.
        return '^(?!.*(?:'.$exempt.')).*?(?:'.$pattern.')';
    }

    public function test(): ServerOpsResult
    {
        return $this->serverOps->run(
            $this->testCommand(),
            ['feature' => 'application', 'op' => 'config_test', 'web_server' => $this->name()],
        );
    }

    public function reload(): ServerOpsResult
    {
        return $this->serverOps->run(
            $this->reloadCommand(),
            ['feature' => 'application', 'op' => 'reload', 'web_server' => $this->name()],
        );
    }

    /**
     * The reload command as something else can run.
     *
     * Exposed for certbot's post-renewal hook, a shell script run by certbot's own timer. Without
     * it a renewed certificate lands on disk while the web server keeps serving the old one from
     * memory.
     *
     * @return array<int, string>
     */
    public function reloadCommandForHook(): array
    {
        return $this->reloadCommand();
    }

    /**
     * Exposed for the per-site fail2ban ban script, which reloads the web
     * server itself and must test the config first, as every panel write does.
     *
     * @return array<int, string>
     */
    public function testCommandForHook(): array
    {
        return $this->testCommand();
    }

    /**
     * @return array<int, string>
     */
    abstract protected function testCommand(): array;

    /**
     * @return array<int, string>
     */
    abstract protected function reloadCommand(): array;
}
