<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\Fail2banOperationException;
use App\Models\Application;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Support\Str;

/**
 * Per-application fail2ban.
 *
 * Stores raw INI for one jail + one filter, scoped to the application's own
 * access log. A different shape from the server-level feature: this watches
 * one site's log, not system auth logs, and the jail name and file paths are
 * derived from the application slug so a rename moves the file rather than
 * orphaning it under the old name.
 *
 * Writes the jail to /etc/fail2ban/jail.d/ and the filter to
 * /etc/fail2ban/filter.d/ — both named with the application's unique safe
 * slug — and reloads the daemon so the new
 * config is live by the time the response returns. Reload, not restart, for
 * the same reason the server-level manager uses reload: a restart forgets
 * every active ban, and the whole point of fail2ban is the bans it remembers.
 */
class ApplicationFail2banManager
{
    /**
     * Every name this feature writes — jail, filter, both files — starts
     * here. They used to be the bare site slug, so a site called `sshd`,
     * `recidive` or `nginx-http-auth` overwrote fail2ban's own filter of that
     * name and replaced the server's jail: SSH protection gone, the panel
     * saying "configured successfully". A list of reserved names would be
     * out of date with the next fail2ban release; nothing fail2ban ships
     * starts with `panel-`, and the panel's own shipped filters are
     * `panel-app-*`, so this cannot collide with either.
     */
    public const NAME_PREFIX = 'panel-site-';

    public function __construct(
        private ServerOps $serverOps,
        private WebServerManager $webServers,
    ) {}

    /**
     * Whether fail2ban is installed and the daemon is reachable.
     * Cached for the lifetime of the request — multiple calls per page load.
     */
    public function installed(): bool
    {
        static $installed = null;

        if ($installed !== null) {
            return $installed;
        }

        $result = $this->serverOps->run(
            [(string) config('server.fail2ban.client', 'fail2ban-client'), 'ping'],
            ['feature' => 'application', 'op' => 'fail2ban_ping'],
            timeout: 10,
        );

        return $installed = $result->ok;
    }

    /**
     * The fail2ban jail name for one application. Always derived from the
     * slug so the file written by enable() can be addressed by the same name
     * by disable() and by any future reload.
     */
    public function jailName(Application $application): string
    {
        return self::NAME_PREFIX.$this->slug($application);
    }

    /**
     * The path the jail file is written to, given the application's slug.
     * Public so the controller can echo the absolute path in test responses
     * without re-implementing the convention.
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
     * The access log path for this site's web server. Falls back to the site's
     * own log directory so an unprovisioned application still has something to
     * point at — enable() writes the file before the log exists, and fail2ban
     * reads the path lazily anyway.
     *
     * The fallback used to be `/var/log/nginx/{slug}.access.log`, which is not
     * where any of the three drivers has put a log since they moved into the
     * site's own directory. It was unreachable — every driver returns an
     * `access` key — but "unreachable" was luck rather than construction, and
     * this is the same shape as the jail body that shipped with nginx's path
     * baked into it and watched a file that does not exist on OpenLiteSpeed.
     * A default that names one web server is a bug waiting for a fourth driver
     * that forgets the key.
     */
    public function getLogPath(Application $application): string
    {
        $paths = $this->webServers->driver()->logPaths($application);

        return $paths['access'] ?? $application->logsPath().'/access.log';
    }

    /**
     * Render the jail INI by replacing the four template placeholders with
     * values from this application. The caller supplies the content they
     * want rendered — the function is a deterministic string transform with
     * no I/O — so the controller can show the user exactly what would be
     * written before it actually is.
     *
     * @return array<string, string>
     */
    public function renderConfigs(Application $application, string $jailContent, string $filterContent): array
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
            'jail' => strtr($jailContent, $replace),
            'filter' => strtr($filterContent, $replace),
        ];
    }

    /**
     * Default jail INI for new applications. Matches the convention the
     * commercial API exposes — WordPress-friendly logpath and the
     * slug-based filter reference.
     *
     * **`backend = auto` is stated, not inherited.** A jail that names a
     * `logpath` and takes its backend from `[DEFAULT]` is one edit away from
     * reading the journal instead, at which point it matches nothing and bans
     * nobody while the panel reports it enabled. That is exactly what happened
     * — `jail.local` carried `backend = systemd` in `[DEFAULT]`, so every jail
     * generated here watched a file fail2ban never opened. Removing that line
     * fixed it; saying `auto` here means a future `[DEFAULT]` cannot break it
     * again. This is the second time this file has shipped a jail that looked
     * enabled and banned nobody; see `defaultFilterContent()` for the first.
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
     * Default filter INI for new applications. Three rules — the standard
     * WordPress login/xmlrpc/admin regexes — and an empty ignore list.
     *
     * **`[Definition]`, not `[{name}]`.** A fail2ban *filter* names its
     * section `Definition`; only a *jail* is named after itself. This emitted
     * the jail name, so the file had no `Definition` section, fail2ban found
     * no `failregex` in it, and the jail banned nobody — while the panel
     * reported it enabled.
     *
     * The two filters this repository already ships,
     * `resources/fail2ban/panel-app-generic.conf` and `panel-app-wplogin.conf`,
     * both get this right; only the default generated here did not.
     */
    public function defaultFilterContent(): string
    {
        return <<<'INI'
            [Definition]
            failregex = ^<HOST> .* "(POST|PUT|DELETE) .*wp-login.php
                       ^<HOST> .* "(POST|PUT|DELETE) .*xmlrpc.php
                       ^<HOST> .* "(POST|PUT|DELETE) .*wp-admin.*
            ignoreregex =

            INI;
    }

    /**
     * Test the rendered config the way fail2ban will actually load it.
     *
     * 🔴 This staged the two files in a temp directory and then ran a bare
     * `fail2ban-client -t` — which tests `/etc/fail2ban`, not the stage. The
     * live config was valid, so *every* submission passed: `[broken` was
     * accepted, written over the live jail and filter, and only the reload
     * after it failed. Reproduced on a real server (2026-09-23). And once the
     * live files were broken, every later save failed the "test" — including
     * the correct one that would have fixed them.
     *
     * Now the whole config tree is copied, the site's two files replaced in
     * the copy, and fail2ban pointed at it with `-c`. Measured on the same
     * server: a broken jail exits 255, the real one 0, and a jail whose log
     * file does not exist fails too — while the live tree is never touched.
     * Root copies it (the tree is root's), and the copy is removed however
     * the test ends.
     *
     * @return array{testOk: bool, output: string}
     */
    public function testConfigs(Application $application, string $jailContent, string $filterContent): array
    {
        $configs = $this->renderConfigs($application, $jailContent, $filterContent);
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
                // The stage is an implementation detail; the user should read
                // the paths their config will really live at.
                'output' => str_replace($stage, $root, trim($result->output()."\n".$result->errorOutput())),
            ];
        } finally {
            $this->serverOps->run(['rm', '-rf', $stage], $context + ['op' => 'fail2ban_stage_cleanup']);
        }
    }

    /**
     * Write the rendered jail + filter to their final paths and reload
     * fail2ban. Called only after testConfigs() passed.
     *
     * The previous files are kept until the reload has succeeded, and put
     * back — with a second reload — if it does not: a jail file fail2ban
     * cannot load is one restart away from fail2ban not starting at all,
     * and with it the sshd jail every other site relies on.
     */
    public function enableForApp(Application $application, string $jailContent, string $filterContent): void
    {
        $configs = $this->renderConfigs($application, $jailContent, $filterContent);
        $files = [
            $this->getJailPath($application) => $configs['jail'],
            $this->getFilterPath($application) => $configs['filter'],
        ];
        $context = ['feature' => 'application', 'application' => $application->id];

        $backups = [];
        foreach (array_keys($files) as $path) {
            $backups[$path] = $this->backup($path, $context);
        }

        try {
            foreach ($files as $path => $content) {
                $this->must($this->serverOps->run(['tee', $path], $context + ['op' => 'fail2ban_write'], input: $content));
            }

            $this->must($this->client(['reload']));
        } catch (Fail2banOperationException $e) {
            $this->restore($backups, $context);
            $this->client(['reload']);

            throw $e;
        }

        foreach (array_filter($backups) as $backup) {
            $this->serverOps->run(['rm', '-f', $backup], $context + ['op' => 'fail2ban_discard_backup']);
        }
    }

    /**
     * Remove the site's jail and filter, and reload so the change is visible
     * immediately.
     *
     * The filter goes too. It used to stay, because under the bare slug there
     * was no telling whether another jail shared it — and a filter left behind
     * under a name like `sshd` was what made a collision permanent. Under the
     * prefix it belongs to this site alone.
     *
     * A site that still has files under its old, unprefixed name (enabled
     * before the prefix, not yet moved by `fail2ban:resync`) loses those as
     * well, with the same care `migrateLegacy()` takes over a filter the
     * fail2ban package owns.
     */
    public function disableForApp(Application $application): void
    {
        $context = ['feature' => 'application', 'application' => $application->id];

        $this->removeLegacyFiles($application);

        $remove = $this->serverOps->run(
            ['rm', '-f', $this->getJailPath($application), $this->getFilterPath($application)],
            $context + ['op' => 'fail2ban_remove_jail'],
        );

        if ($remove->failed()) {
            throw new Fail2banOperationException($remove->reference);
        }

        $reload = $this->client(['reload']);

        if ($reload->failed()) {
            throw new Fail2banOperationException($reload->reference);
        }
    }

    /**
     * Move a jail enabled under the bare slug to the prefixed name.
     *
     * Rewrites the saved content so a literal old name in it follows (a
     * section `[shop]`, `filter = shop` — what the old structured form
     * generated), writes the new files through `enableForApp()` (tested
     * reload, rolled back on failure), then removes the old jail file. The
     * old filter is removed only when the fail2ban package does not own it:
     * a package-owned file under that name means the site had already
     * overwritten fail2ban's own filter (`sshd.conf`), and deleting it would
     * leave the server jail referencing a filter that is gone — fail2ban
     * would not start. That one is reported, not touched.
     *
     * @return array{moved: bool, damaged_filter: string|null}
     */
    public function migrateLegacy(Application $application): array
    {
        $legacy = $this->legacyName($application);

        if ($legacy === null || $application->fail2ban_jail_content === null) {
            return ['moved' => false, 'damaged_filter' => null];
        }

        $jail = $this->placeholderize((string) $application->fail2ban_jail_content, $legacy);
        $filter = $this->placeholderize((string) $application->fail2ban_filter_content, $legacy, filter: true);

        $this->enableForApp($application, $jail, $filter);
        $damaged = $this->removeLegacyFiles($application);
        $this->must($this->client(['reload']));

        $application->forceFill([
            'fail2ban_jail_name' => $this->jailName($application),
            'fail2ban_jail_content' => $jail,
            'fail2ban_filter_content' => $filter,
        ])->save();

        return ['moved' => true, 'damaged_filter' => $damaged];
    }

    /**
     * Remove the files a site still has under its old, unprefixed name.
     * No reload: every caller reloads once for all of its changes.
     *
     * The old filter only when the fail2ban package does not own it — see
     * `migrateLegacy()`. Returns that filter's path when it was kept for that
     * reason, so the caller can say so.
     *
     * @throws Fail2banOperationException
     */
    public function removeLegacyFiles(Application $application): ?string
    {
        $legacy = $this->legacyName($application);

        if ($legacy === null) {
            return null;
        }

        $context = ['feature' => 'application', 'application' => $application->id];
        $oldFilter = $this->filterPathFor($legacy);
        $damaged = $this->packageOwns($oldFilter, $context) ? $oldFilter : null;

        $this->must($this->serverOps->run(
            ['rm', '-f', $this->jailPathFor($legacy), ...($damaged === null ? [$oldFilter] : [])],
            $context + ['op' => 'fail2ban_remove_legacy'],
        ));

        return $damaged;
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
     * The unprefixed name this site's files were written under, when it has
     * any. Null for a site with no jail or one already on the prefix.
     */
    private function legacyName(Application $application): ?string
    {
        $stored = $application->fail2ban_jail_name;

        return $stored === null || $stored === '' || str_starts_with($stored, self::NAME_PREFIX) ? null : $stored;
    }

    /**
     * Whether a file under /etc/fail2ban came from the fail2ban package.
     * Asked of dpkg rather than a list: a list is out of date with the next
     * release. When dpkg cannot answer, the file is treated as owned — the
     * cost of keeping a stray file is nothing, the cost of deleting a real
     * one is fail2ban not starting.
     *
     * @param  array<string, mixed>  $context
     */
    private function packageOwns(string $path, array $context): bool
    {
        $result = $this->serverOps->probe(['dpkg-query', '-S', $path], $context + ['op' => 'fail2ban_package_owner']);

        if ($result->ok) {
            return true;
        }

        // "Not owned" is exit 1 *with* a line on stderr — measured on Ubuntu
        // 26.04 — so the generic `answered` (exit 1 and silent) never sees it,
        // and reading that as "could not find out" made every file look
        // package-owned. Anything else, sudo refusing included, stays unknown.
        return ! ($result->exitCode() === 1
            && str_contains($result->errorOutput(), 'no path found matching pattern'));
    }

    /**
     * Put the placeholders back where saved content spelled the old name.
     * A jail section `[old]` becomes `[{name}]`, `filter = old` becomes
     * `filter = {filter}`; in a filter, a section `[old]` was never valid —
     * fail2ban reads `[Definition]` — so it becomes that.
     */
    private function placeholderize(string $content, string $legacy, bool $filter = false): string
    {
        $name = preg_quote($legacy, '/');

        $content = (string) preg_replace('/^(\s*)\['.$name.'\](\s*)$/m', $filter ? '$1[Definition]$2' : '$1[{name}]$2', $content);

        return $filter ? $content : (string) preg_replace('/^(\s*filter\s*=\s*)'.$name.'(\s*)$/m', '$1{filter}$2', $content);
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
     * `.panel-bak` because fail2ban only reads `*.conf` and `*.local`, so the
     * copy sitting beside the original is never loaded as a second jail.
     *
     * @param  array<string, mixed>  $context
     */
    private function backup(string $path, array $context): ?string
    {
        $exists = $this->serverOps->probe(['test', '-f', $path], $context + ['op' => 'fail2ban_backup_check']);

        if (! $exists->answered) {
            throw new Fail2banOperationException($exists->reference);
        }

        if (! $exists->ok) {
            return null;
        }

        $backup = $path.'.panel-bak';
        $this->must($this->serverOps->run(['cp', '-p', $path, $backup], $context + ['op' => 'fail2ban_backup']));

        return $backup;
    }

    /**
     * Put every file back as it was: the old one where there was one, none
     * where there was not.
     *
     * @param  array<string, ?string>  $backups
     * @param  array<string, mixed>  $context
     */
    private function restore(array $backups, array $context): void
    {
        foreach ($backups as $path => $backup) {
            $this->serverOps->run(
                $backup === null ? ['rm', '-f', $path] : ['mv', '-f', $backup, $path],
                $context + ['op' => 'fail2ban_restore'],
            );
        }
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
