<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\Fail2banConfigRejectedException;
use App\Exceptions\Server\Application\Fail2banOperationException;
use App\Models\Application;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Per-application fail2ban: raw INI for one jail + one filter, scoped to the site's own access log.
 *
 * The jail goes to /etc/fail2ban/jail.d/ and the filter to filter.d/, named from the application's
 * slug (so a rename moves the files), and the daemon is reloaded so the config is live on return.
 * Reload, not restart: a restart forgets every active ban.
 */
class ApplicationFail2banManager
{
    /** Whether fail2ban answers here, asked once per instance ({@see self::installed()}). */
    private ?bool $installed = null;

    /**
     * Prefix of every name this feature writes (jail, filter, files). With bare slugs a site called
     * `sshd` or `recidive` overwrote fail2ban's own filter and jail. Nothing fail2ban ships starts
     * with `panel-`, and the panel's own filters are `panel-app-*`, so this cannot collide.
     */
    public const NAME_PREFIX = 'panel-site-';

    public function __construct(
        private ServerOps $serverOps,
        private WebServerManager $webServers,
    ) {}

    /**
     * Whether fail2ban is installed and the daemon is reachable (cached per instance).
     */
    public function installed(): bool
    {
        // Per instance, not a function `static`: a static outlives the request in a queue worker and
        // would keep saying "missing" after fail2ban was installed.
        if ($this->installed !== null) {
            return $this->installed;
        }

        $result = $this->serverOps->run(
            [(string) config('server.fail2ban.client', 'fail2ban-client'), 'ping'],
            ['feature' => 'application', 'op' => 'fail2ban_ping'],
            timeout: 10,
        );

        return $this->installed = $result->ok;
    }

    /**
     * The fail2ban jail name for one application, derived from the slug so enable() and disable()
     * address the same file.
     */
    public function jailName(Application $application): string
    {
        $slug = $this->slug($application);
        $stored = $application->fail2ban_jail_name;

        if ($stored !== null && $stored !== '') {
            if (! in_array($stored, [$slug, self::NAME_PREFIX.$slug], true)
                || ! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]*\z/D', $stored)) {
                throw ValidationException::withMessages(['jail_config_content' => __('fail2ban.validation.foreign_jail', [
                    'section' => $stored, 'name' => self::NAME_PREFIX.$slug,
                ])]);
            }

            return $stored;
        }

        return self::NAME_PREFIX.$slug;
    }

    /**
     * The path the jail file is written to. Public so the controller can echo it without
     * re-implementing the convention.
     */
    public function getJailPath(Application $application): string
    {
        return $this->jailPathFor($this->jailName($application));
    }

    public function getFilterPath(Application $application): string
    {
        return $this->filterPathFor($this->jailName($application));
    }

    private function jailPathFor(string $name): string
    {
        return rtrim((string) config('server.fail2ban_apps.jail_d', '/etc/fail2ban/jail.d'), '/')."/{$name}.conf";
    }

    private function filterPathFor(string $name): string
    {
        return rtrim((string) config('server.fail2ban_apps.filter_d', '/etc/fail2ban/filter.d'), '/')."/{$name}.conf";
    }

    /**
     * The access log path for this site's web server. Falls back to the site's own log directory,
     * so an unprovisioned application still has a path (enable() writes before the log exists).
     * A default naming one web server's path is a bug waiting for a new driver that forgets the key.
     */
    public function getLogPath(Application $application): string
    {
        $paths = $this->webServers->driver()->logPaths($application);

        // Watch both HTTP and HTTPS logs: with HTTPS forced, every real request is in access-ssl.log.
        if (isset($paths['access'], $paths['access_ssl'])) {
            return dirname($paths['access']).'/access*log';
        }

        return $paths['access'] ?? $application->logsPath().'/access.log';
    }

    /**
     * Render the jail and filter INI by replacing the template placeholders with this application's
     * values. A pure string transform (no I/O), so the controller can show what would be written.
     *
     * @return array<string, string>
     */
    public function renderConfigs(Application $application, string $jailContent, string $filterContent, bool $withBanAction = false): array
    {
        $slug = $this->slug($application);
        $logpath = $this->getLogPath($application);
        $name = $this->jailName($application);

        $replace = [
            '{name}' => $name,
            '{filter}' => $name,
            '{logpath}' => $logpath,
            '{slug}' => $slug,
        ];

        return [
            // The ban is the panel's, never the jail author's: this site only, in its own web-server
            // config. The server's default action banned at the firewall on 80/443, which cut the
            // address off every site and the panel. `action` is not an allowed jail key.
            'jail' => $withBanAction && $this->legacyName($application) === null
                ? rtrim(strtr($jailContent, $replace))."\n".'action   = '.self::BAN_ACTION.'[slug="'.basename($application->siteRulesPath()).'"]'."\n"
                // What the form shows and saves: without the panel's own line.
                : strtr($jailContent, $replace),
            'filter' => strtr($filterContent, $replace),
        ];
    }

    /** The fail2ban action every site jail bans with. */
    public const BAN_ACTION = 'panel-site-ban';

    /**
     * Write the ban action and the script it calls when either differs from what the panel would
     * write. Both are server-wide, shared by every site jail, and rendered for this web server.
     */
    public function ensureBanTools(): void
    {
        $driver = $this->webServers->driver();
        $script = (string) config('server.fail2ban_apps.ban_script', '/usr/local/sbin/panel-site-ban');
        $context = ['feature' => 'application', 'op' => 'fail2ban_ban_tools'];

        $files = [
            $script => [View::make('server.fail2ban.site-ban-script', [
                'webServer' => $driver->name(),
                'self' => $script,
                'rulesRootQuoted' => escapeshellarg(rtrim((string) config('server.site_rules_root'), '/')),
                'test' => implode(' ', array_map('escapeshellarg', $driver->testCommandForHook())),
                'reload' => implode(' ', array_map('escapeshellarg', $driver->reloadCommandForHook())),
                'olsTmpQuoted' => escapeshellarg(rtrim((string) config('server.web_server_drivers.openlitespeed.tmp_dir', '/tmp/lshttpd'), '/')),
            ])->render(), '0755'],
            rtrim((string) config('server.fail2ban_apps.action_d', '/etc/fail2ban/action.d'), '/').'/'.self::BAN_ACTION.'.conf' => [
                View::make('server.fail2ban.site-ban-action', ['script' => $script])->render(), '0644',
            ],
        ];

        foreach ($files as $path => [$contents, $mode]) {
            $current = $this->serverOps->probe(['cat', $path], $context + ['path' => $path]);

            if ($current->ok && $current->output() === $contents) {
                continue;
            }

            $this->must($this->serverOps->run(['tee', $path], $context + ['path' => $path], input: $contents));
            $this->must($this->serverOps->run(['chmod', $mode, $path], $context + ['path' => $path]));
        }
    }

    /**
     * The only settings a site's own jail may set: tuning and scope (what to watch, how many
     * failures, for how long), never what fail2ban does about a ban. Everything in
     * defaultJailContent() is here.
     */
    public const ALLOWED_JAIL_KEYS = [
        'enabled', 'port', 'filter', 'logpath', 'maxretry', 'findtime', 'bantime', 'ignoreip', 'backend',
    ];

    /**
     * Settings in a jail that are not in ALLOWED_JAIL_KEYS, lowercased, in order of appearance.
     * Indented continuation lines belong to the key above them.
     *
     * @return array<int, string>
     */
    public function disallowedJailKeys(string $jail): array
    {
        $found = [];

        foreach (preg_split('/\r?\n/', $jail) ?: [] as $line) {
            if ($line === '' || ctype_space($line[0]) || preg_match('/^\s*[#;\[]/', $line) === 1) {
                continue;
            }

            if (preg_match('/^([^=:]+?)\s*[=:]/', $line, $m) !== 1) {
                continue;
            }

            $key = strtolower(trim($m[1]));

            if (! in_array($key, self::ALLOWED_JAIL_KEYS, true)) {
                $found[] = $key;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Default jail INI for new applications (WordPress-friendly logpath, slug-based filter).
     *
     * `backend = auto` is stated, not inherited: a jail that takes its backend from `[DEFAULT]` can
     * end up reading the journal instead of its logpath, matching nothing while shown as enabled.
     */
    public function defaultJailContent(): string
    {
        return <<<'INI'
            [{name}]
            enabled  = true
            backend  = auto
            port     = http,https
            filter   = {filter}
            logpath  = {logpath}
            maxretry = 3
            bantime  = 3600
            findtime = 600

            INI;
    }

    /**
     * Default filter INI for new applications: WordPress's login and XML-RPC endpoints, and an
     * empty ignore list.
     *
     * 🔴 No `POST .*wp-admin.*` rule: everything a logged-in admin does in the dashboard is a POST
     * under /wp-admin/ (Heartbeat's admin-ajax.php, post.php, options.php), so it banned the site's
     * own administrators. A brute force needs the login form or XML-RPC.
     *
     * The section must be `[Definition]`, not `[{name}]` (only a jail is named after itself);
     * otherwise fail2ban finds no `failregex` and the jail bans nobody while shown as enabled.
     */
    public function defaultFilterContent(): string
    {
        return <<<'INI'
            [Definition]
            failregex = ^<HOST> .* "POST [^"]*wp-login\.php[^"]*" 200\b
                       ^<HOST> .* "POST [^"]*xmlrpc\.php[^"]*" 200\b
            ignoreregex =

            INI;
    }

    /**
     * Earlier defaults, replaced when a site still has one word for word: one with a `wp-admin`
     * rule that banned logged-in administrators, and one that counted every login POST, so a
     * successful login counted too. WordPress answers a wrong password with 200 and a right one
     * with a 302, so only 200 counts now; XML-RPC answers 200 either way, so every POST counts.
     */
    private const LEGACY_DEFAULT_FILTERS = [
        <<<'INI'
        [Definition]
        failregex = ^<HOST> .* "(POST|PUT|DELETE) .*wp-login.php
                   ^<HOST> .* "(POST|PUT|DELETE) .*xmlrpc.php
                   ^<HOST> .* "(POST|PUT|DELETE) .*wp-admin.*
        ignoreregex =
        INI,
        <<<'INI'
        [Definition]
        failregex = ^<HOST> .* "(POST|PUT|DELETE) .*wp-login.php
                   ^<HOST> .* "(POST|PUT|DELETE) .*xmlrpc.php
        ignoreregex =
        INI,
    ];

    /**
     * Replace a site's filter with the current default when it is still, word for word, an old one.
     *
     * A filter the user edited is theirs and is left alone. Whitespace is compared loosely (the form
     * may re-indent it), but every rule must match. Written through `enableForApp()`, so a failed
     * reload restores the previous file.
     *
     * @throws Fail2banOperationException
     */
    public function repairLegacyDefaultFilter(Application $application): bool
    {
        $words = fn (string $text): string => (string) preg_replace('/\s+/', ' ', trim($text));

        // A kept v7 jail is the user's; a deploy does not rewrite its filter.
        if ($application->fail2ban_jail_name === null
            || $this->legacyName($application) !== null
            || $application->fail2ban_jail_content === null
            || ! in_array($words((string) $application->fail2ban_filter_content), array_map($words, self::LEGACY_DEFAULT_FILTERS), true)) {
            return false;
        }

        $filter = $this->defaultFilterContent();

        $this->enableForApp($application, (string) $application->fail2ban_jail_content, $filter);

        $application->forceFill(['fail2ban_filter_content' => $filter])->save();

        return true;
    }

    /**
     * Test the rendered config the way fail2ban will actually load it.
     *
     * Copies the whole config tree, replaces the site's two files in the copy and points
     * `fail2ban-client -t` at it with `-c`; the live tree is never touched. A bare `-t` tests
     * /etc/fail2ban, not the stage, so every submission passed and broken config reached the live
     * files. Root copies the tree (it is root's); the copy is removed however the test ends.
     *
     * @return array{testOk: bool, output: string}
     */
    public function testConfigs(Application $application, string $jailContent, string $filterContent): array
    {
        // The jail names the ban action, so the test needs it in the tree.
        $this->assertLegacyOwnership($application);

        if ($this->legacyName($application) === null) {
            $this->ensureBanTools();
        }

        $configs = $this->renderConfigs($application, $jailContent, $filterContent, withBanAction: true);
        $root = $this->configRoot();
        $stage = sys_get_temp_dir().'/panel-f2b-test-'.Str::uuid();
        $context = ['feature' => 'application', 'application' => $application->id];

        try {
            // Plain `mkdir`, not `-p`: it fails if the path already exists,
            // so nothing planted there in advance can be reused as the stage.
            $this->must($this->serverOps->run(['mkdir', $stage], $context + ['op' => 'fail2ban_stage']));
            $this->must($this->serverOps->run(['cp', '-a', $root.'/.', $stage.'/'], $context + ['op' => 'fail2ban_stage_copy']));

            $this->must($this->serverOps->run(
                ['tee', $this->staged($stage, $this->getJailPath($application))],
                $context + ['op' => 'fail2ban_stage_jail'],
                input: $configs['jail'],
            ));
            $this->must($this->serverOps->run(
                ['tee', $this->staged($stage, $this->getFilterPath($application))],
                $context + ['op' => 'fail2ban_stage_filter'],
                input: $configs['filter'],
            ));

            $result = $this->serverOps->run(
                [(string) config('server.fail2ban.client', 'fail2ban-client'), '-c', $stage, '-t'],
                $context + ['op' => 'fail2ban_test'],
                timeout: 30,
            );

            return [
                'testOk' => $result->ok,
                // The stage is an implementation detail; show the paths the config will really live at.
                'output' => str_replace($stage, $root, trim($result->output()."\n".$result->errorOutput())),
            ];
        } finally {
            $this->serverOps->run(['rm', '-rf', $stage], $context + ['op' => 'fail2ban_stage_cleanup']);
        }
    }

    /**
     * Write the rendered jail + filter to their final paths and reload fail2ban. Called only after
     * testConfigs() passed.
     *
     * The previous files are kept until the reload succeeds and put back (with a second reload) if
     * it does not: a jail file fail2ban cannot load is one restart from fail2ban not starting, and
     * with it the sshd jail.
     */
    public function enableForApp(Application $application, string $jailContent, string $filterContent, bool $keepCopy = true): void
    {
        // Every write of a site jail passes here, including re-enabling one saved before the
        // allowlist existed; refused with the same message the form gives.
        $disallowed = $this->disallowedJailKeys($jailContent);

        if ($disallowed !== []) {
            throw ValidationException::withMessages(['jail_config_content' => __('fail2ban.validation.disallowed_setting', [
                'setting' => $disallowed[0],
                'allowed' => implode(', ', self::ALLOWED_JAIL_KEYS),
            ])]);
        }

        $this->assertLegacyOwnership($application);

        if ($this->legacyName($application) === null) {
            $this->ensureBanTools();
        }

        $configs = $this->renderConfigs($application, $jailContent, $filterContent, withBanAction: true);
        $files = [
            $this->getJailPath($application) => $configs['jail'],
            $this->getFilterPath($application) => $configs['filter'],
        ];
        $context = ['feature' => 'application', 'application' => $application->id];

        $backups = [];
        foreach (array_keys($files) as $path) {
            $backups[$path] = $this->backup($path, $context, $keepCopy);
        }

        try {
            foreach ($files as $path => $content) {
                $this->must($this->serverOps->run(['tee', $path], $context + ['op' => 'fail2ban_write'], input: $content));
            }

            $reload = $this->client(['reload']);

            // fail2ban answered and said no: about the user's config, in its
            // own words. Anything else (no socket, timeout) stays a 500.
            if ($reload->failed() && str_contains($reload->errorOutput(), 'NOK:')) {
                throw new Fail2banConfigRejectedException($reload->reference, $this->readable($reload->errorOutput()));
            }

            $this->must($reload);
        } catch (Fail2banOperationException|Fail2banConfigRejectedException $e) {
            $this->restore($backups, $context);
            $this->client(['reload']);

            throw $e;
        }

        $this->discard($backups, $context);
    }

    /**
     * Remove the site's jail and filter, and reload so the change is live at once.
     *
     * A site still on its old, unprefixed name (not yet moved by `fail2ban:resync`) loses those
     * files too, with the same care `migrateLegacy()` takes over a filter the fail2ban package owns.
     *
     * @param  bool  $reload  false when fail2ban is not installed: the files go,
     *                        and there is no daemon to tell.
     */
    public function disableForApp(Application $application, bool $keepCopy = true, bool $reload = true): void
    {
        $context = ['feature' => 'application', 'application' => $application->id];

        $this->assertLegacyOwnership($application);

        $backups = [];
        foreach ([$this->getJailPath($application), $this->getFilterPath($application)] as $path) {
            $backups[$path] = $this->backup($path, $context, $keepCopy);
        }

        try {
            $this->must($this->serverOps->run(
                ['rm', '-f', ...array_keys($backups)],
                $context + ['op' => 'fail2ban_remove_jail'],
            ));

            if ($reload) {
                $this->must($this->client(['reload']));
            }
        } catch (Fail2banOperationException $exception) {
            $this->restore($backups, $context);
            $this->client(['reload']);
            throw $exception;
        }

        $this->discard($backups, $context);
    }

    /**
     * Kept for callers of the previous namespace migration API. Existing v7 jails stay in place;
     * this method performs no I/O.
     *
     * @return array{moved: bool, damaged_filter: string|null}
     */
    public function migrateLegacy(Application $application): array
    {
        // Compatibility keeps an owned v7 jail in place. A deploy must not
        // rename it, change its ban scope or rewrite its custom filter.
        return ['moved' => false, 'damaged_filter' => null];
    }

    /**
     * Compatibility shim: no deploy-time namespace cleanup is permitted.
     * Explicit disable removes only the owned actual jail and filter.
     */
    public function removeLegacyFiles(Application $application): ?string
    {
        $this->assertLegacyOwnership($application);

        // No namespace migration or second-file cleanup: disableForApp()
        // removes the actual, ownership-checked jail transactionally.
        return null;
    }

    public function hasLegacyFiles(Application $application): bool
    {
        return $this->legacyName($application) !== null;
    }

    /**
     * @throws Fail2banOperationException
     */
    public function reload(): void
    {
        $this->must($this->client(['reload']));
    }

    /**
     * A stored bare name is not authority to overwrite a system or foreign
     * file. Require package-negative, root-owned regular files matching the
     * recorded configuration; refuse unknown ownership before any write.
     */
    public function assertLegacyOwnership(Application $application): void
    {
        if ($this->legacyName($application) === null) {
            return;
        }

        $name = $this->jailName($application);
        $context = ['feature' => 'application', 'application' => $application->id];
        $saved = $this->renderConfigs($application,
            (string) $application->fail2ban_jail_content,
            (string) $application->fail2ban_filter_content,
        );
        $paths = [$this->getJailPath($application) => $saved['jail'], $this->getFilterPath($application) => $saved['filter']];

        foreach ($paths as $path => $expected) {
            $stat = $this->serverOps->probe(['stat', '-c', '%u:%h:%F', '--', $path], $context + ['op' => 'fail2ban_ownership']);
            $content = $this->serverOps->probe(['cat', '--', $path], $context + ['op' => 'fail2ban_ownership']);

            if ($this->packageOwns($path, $context)
                || ! $stat->ok || trim($stat->output()) !== '0:1:regular file'
                || ! $content->ok || trim($content->output()) !== trim($expected)) {
                throw ValidationException::withMessages(['jail_config_content' => __('fail2ban.validation.foreign_jail', [
                    'section' => $name, 'name' => self::NAME_PREFIX.$this->slug($application),
                ])]);
            }
        }

        // stat does not follow symlinks. Verify the configured parent tree
        // too; a safe file beneath a writable/symlinked directory is not safe.
        foreach (array_unique(array_map(dirname(...), array_keys($paths))) as $directory) {
            do {
                $stat = $this->serverOps->probe(['stat', '-c', '%u:%a:%F', '--', $directory], $context + ['op' => 'fail2ban_ownership']);
                [$owner, $mode, $type] = array_pad(explode(':', trim($stat->output()), 3), 3, '');
                if (! $stat->ok || $owner !== '0' || $type !== 'directory'
                    || ! ctype_digit($mode) || (octdec($mode) & 0022) !== 0) {
                    $this->rejectOwnership($application, $name);
                }
                $directory = dirname($directory);
            } while ($directory !== '/');
        }

        // No new privileged binary: find/cat are already allowlisted. Check
        // all configuration fragments for a competing section with this name.
        $inventory = $this->serverOps->probe(['find', $this->configRoot(), '(', '-type', 'f', '-o', '-type', 'l', ')', '(', '-name', '*.conf', '-o', '-name', '*.local', ')'],
            $context + ['op' => 'fail2ban_ownership_sections']);
        if (! $inventory->ok) {
            $this->rejectOwnership($application, $name);
        }
        $found = [];
        foreach (array_filter(explode("\n", trim($inventory->output()))) as $path) {
            $content = $this->serverOps->probe(['cat', '--', $path], $context + ['op' => 'fail2ban_ownership_sections']);
            if (! $content->ok) {
                $this->rejectOwnership($application, $name);
            }
            if ($path !== $this->getJailPath($application)
                && preg_match('/^\\s*filter\\s*[=:]\\s*'.preg_quote($name, '/').'(?:\\s|\\[|$)/mi', $content->output())) {
                $this->rejectOwnership($application, $name);
            }
            // One entry per section, so a duplicate inside one file counts too.
            array_push($found, ...array_fill(0, $this->definesSection($content->output(), $name), $path));
        }
        if ($found !== [$this->getJailPath($application)]) {
            $this->rejectOwnership($application, $name);
        }
    }

    private function rejectOwnership(Application $application, string $name): never
    {
        throw ValidationException::withMessages(['jail_config_content' => __('fail2ban.validation.foreign_jail', [
            'section' => $name, 'name' => self::NAME_PREFIX.$this->slug($application),
        ])]);
    }

    /**
     * The unprefixed name this site's files were written under; null for no jail or one already
     * on the prefix.
     */
    private function legacyName(Application $application): ?string
    {
        $stored = $application->fail2ban_jail_name;

        // Exact, not a prefix test: a v7 site whose slug starts with `panel-site-` stored that bare
        // slug, which is not the generated `panel-site-{slug}` and must still prove its ownership.
        return $stored === $this->slug($application) ? $stored : null;
    }

    /**
     * Whether a fragment defines a section called $name. Errs toward yes: ConfigParser takes
     * everything up to the last `]` as the header and fail2ban strips `#`/`;` inline comments, so
     * `[shop] # note` and `[ shop ]` both count.
     */
    private function definesSection(string $content, string $name): int
    {
        preg_match_all('/^[ \t]*\[([^\r\n]*)\]/m', $content, $matches);

        $count = 0;
        foreach ($matches[1] as $header) {
            $candidates = [
                $header,
                strstr($header, ']', true) ?: $header,
                preg_split('/\s[#;]/', $header)[0] ?? $header,
            ];
            foreach ($candidates as $candidate) {
                if (strcasecmp(trim(rtrim(trim($candidate), ']')), $name) === 0) {
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }

    /**
     * Whether a file under /etc/fail2ban came from the fail2ban package, asked of dpkg rather than a
     * list (a list goes stale). When dpkg cannot answer the file is treated as owned: keeping a
     * stray file costs nothing, deleting a real one stops fail2ban from starting.
     *
     * @param  array<string, mixed>  $context
     */
    private function packageOwns(string $path, array $context): bool
    {
        $result = $this->serverOps->probe(['dpkg-query', '-S', $path], $context + ['op' => 'fail2ban_package_owner']);

        if ($result->ok) {
            return true;
        }

        // "Not owned" is exit 1 *with* a line on stderr, so the generic `answered` (exit 1 and
        // silent) never sees it, and reading that as "unknown" made every file look package-owned.
        // Anything else, sudo refusing included, stays unknown.
        return ! ($result->exitCode() === 1
            && str_contains($result->errorOutput(), 'no path found matching pattern'));
    }

    /** `/etc/fail2ban` — the tree `jail.d` sits in. */
    private function configRoot(): string
    {
        return dirname(rtrim((string) config('server.fail2ban_apps.jail_d', '/etc/fail2ban/jail.d'), '/'));
    }

    /** The same file inside the stage: `/etc/fail2ban/jail.d/x.conf` → `{stage}/jail.d/x.conf`. */
    private function staged(string $stage, string $path): string
    {
        return $stage.'/'.basename(dirname($path)).'/'.basename($path);
    }

    /**
     * Copy a file aside before it is overwritten; null when there was none.
     *
     * `.panel-bak` because fail2ban only reads `*.conf` and `*.local`, so the copy beside the
     * original is never loaded as a second jail.
     *
     * With `$keepCopy` false no file is written: the old text is held in memory for the reload and
     * written back if fail2ban refuses the new one.
     *
     * @param  array<string, mixed>  $context
     * @return array{file?: string, content?: string}|null
     */
    private function backup(string $path, array $context, bool $keepCopy = true): ?array
    {
        $exists = $this->serverOps->probe(['test', '-f', $path], $context + ['op' => 'fail2ban_backup_check']);

        if (! $exists->answered) {
            throw new Fail2banOperationException($exists->reference);
        }

        if (! $exists->ok) {
            return null;
        }

        if (! $keepCopy) {
            $read = $this->serverOps->run(['cat', '--', $path], $context + ['op' => 'fail2ban_read_previous']);
            $this->must($read);

            return ['content' => $read->output()];
        }

        $backup = $path.'.panel-bak';
        $this->must($this->serverOps->run(['cp', '-p', $path, $backup], $context + ['op' => 'fail2ban_backup']));

        return ['file' => $backup];
    }

    /**
     * Put every file back as it was: the old one where there was one, none
     * where there was not.
     *
     * @param  array<string, array{file?: string, content?: string}|null>  $backups
     * @param  array<string, mixed>  $context
     */
    private function restore(array $backups, array $context): void
    {
        foreach ($backups as $path => $backup) {
            $this->serverOps->run(
                match (true) {
                    $backup === null => ['rm', '-f', $path],
                    isset($backup['file']) => ['mv', '-f', $backup['file'], $path],
                    default => ['tee', $path],
                },
                $context + ['op' => 'fail2ban_restore'],
                input: $backup['content'] ?? null,
            );
        }
    }

    /**
     * The copies, once the change has held. Nothing to do for one kept only in
     * memory.
     *
     * @param  array<string, array{file?: string, content?: string}|null>  $backups
     * @param  array<string, mixed>  $context
     */
    private function discard(array $backups, array $context): void
    {
        foreach ($backups as $backup) {
            if (isset($backup['file'])) {
                $this->serverOps->run(['rm', '-f', $backup['file']], $context + ['op' => 'fail2ban_discard_backup']);
            }
        }
    }

    /**
     * fail2ban's log lines without the timestamp and process prefix.
     */
    private function readable(string $output): string
    {
        return collect(explode("\n", trim($output)))
            ->map(fn (string $line) => preg_replace('/^\d{4}-\d\d-\d\d [\d:,]+\s+\S+\s+\[\d+\]:\s+/', '', $line) ?? $line)
            ->map(fn (string $line) => preg_replace('/\s{2,}/', ' ', trim($line)) ?? $line)
            ->filter()
            ->implode("\n");
    }

    private function must(ServerOpsResult $result): void
    {
        if ($result->failed()) {
            throw new Fail2banOperationException($result->reference);
        }
    }

    private function slug(Application $application): string
    {
        return (string) ($application->slug ?: $application->domain ?: ('app-'.$application->id));
    }

    /**
     * @param  array<int, string>  $args
     */
    private function client(array $args): ServerOpsResult
    {
        return $this->serverOps->run(
            [(string) config('server.fail2ban.client', 'fail2ban-client'), ...$args],
            ['feature' => 'application', 'op' => 'fail2ban_'.($args[0] ?? 'client')],
        );
    }
}
