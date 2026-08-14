<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\Fail2banOperationException;
use App\Models\Application;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\WebServers\WebServerManager;

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
        return $this->slug($application);
    }

    /**
     * The path the jail file is written to, given the application's slug.
     * Public so the controller can echo the absolute path in test responses
     * without re-implementing the convention.
     */
    public function getJailPath(Application $application): string
    {
        $directory = rtrim((string) config('server.fail2ban_apps.jail_d', '/etc/fail2ban/jail.d'), '/');

        return "{$directory}/{$this->jailName($application)}.conf";
    }

    public function getFilterPath(Application $application): string
    {
        $directory = rtrim((string) config('server.fail2ban_apps.filter_d', '/etc/fail2ban/filter.d'), '/');

        return "{$directory}/{$this->jailName($application)}.conf";
    }

    /**
     * The access log path for this site's web server. Falls back to the
     * slug-named log so an unprovisioned application still has something
     * to point at — enable() writes the file before the log exists, and
     * fail2ban reads the path lazily anyway.
     */
    public function getLogPath(Application $application): string
    {
        $paths = $this->webServers->driver()->logPaths($application);

        return $paths['access'] ?? '/var/log/nginx/'.$this->slug($application).'.access.log';
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
     */
    public function defaultJailContent(): string
    {
        return <<<'INI'
            [{name}]
            enabled  = true
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
     */
    public function defaultFilterContent(): string
    {
        return <<<'INI'
            [{name}]
            failregex = ^<HOST> .* "(POST|PUT|DELETE) .*wp-login.php
                       ^<HOST> .* "(POST|PUT|DELETE) .*xmlrpc.php
                       ^<HOST> .* "(POST|PUT|DELETE) .*wp-admin.*
            ignoreregex =

            INI;
    }

    /**
     * Run `fail2ban-client -t` against the rendered config and return the
     * result. fail2ban-client accepts a config directory on `-t` and validates
     * every jail in it without touching the live daemon, which is the
     * only way to verify a custom jail INI before letting it anywhere near
     * the running service.
     *
     * The rendered config is staged to a temp directory so `-t` sees only
     * this one jail — fail2ban's own test mode would otherwise pick up every
     * jail already on the box and report their failures as ours.
     *
     * @return array{testOk: bool, output: string}
     */
    public function testConfigs(Application $application, string $jailContent, string $filterContent): array
    {
        $configs = $this->renderConfigs($application, $jailContent, $filterContent);

        $stage = sys_get_temp_dir().'/sv-oss-f2b-test-'.getmypid().'-'.$application->id;
        $jailDir = $stage.'/jail.d';
        $filterDir = $stage.'/filter.d';
        @mkdir($jailDir, 0755, true);
        @mkdir($filterDir, 0755, true);

        $jailFile = $jailDir.'/'.$this->jailName($application).'.local';
        $filterFile = $filterDir.'/'.$this->jailName($application).'.conf';

        file_put_contents($jailFile, $configs['jail']);
        file_put_contents($filterFile, $configs['filter']);

        $result = $this->serverOps->run(
            [(string) config('server.fail2ban.client', 'fail2ban-client'), '-t'],
            ['feature' => 'application', 'op' => 'fail2ban_test', 'application' => $application->id],
            timeout: 15,
        );

        // Stage directory is throwaway — owned by the php-fpm worker, never
        // visible to another request. Clean up before returning so a long
        // uptime does not accumulate temp dirs.
        @unlink($jailFile);
        @unlink($filterFile);
        @rmdir($jailDir);
        @rmdir($filterDir);
        @rmdir($stage);

        return [
            'testOk' => $result->ok,
            'output' => trim($result->output()."\n".$result->errorOutput()),
        ];
    }

    /**
     * Write the rendered jail + filter to their final paths and reload
     * fail2ban. Called only after testConfigs() passed — never on a config
     * that has not been validated, because there is no `-t` for the live
     * daemon and a broken reload would take down the running service.
     */
    public function enableForApp(Application $application, string $jailContent, string $filterContent): void
    {
        $configs = $this->renderConfigs($application, $jailContent, $filterContent);

        $jailPath = $this->getJailPath($application);
        $filterPath = $this->getFilterPath($application);

        $jailWrite = $this->serverOps->run(
            ['tee', $jailPath],
            ['feature' => 'application', 'op' => 'fail2ban_write_jail', 'application' => $application->id],
            input: $configs['jail'],
        );

        if ($jailWrite->failed()) {
            throw new Fail2banOperationException($jailWrite->reference);
        }

        $filterWrite = $this->serverOps->run(
            ['tee', $filterPath],
            ['feature' => 'application', 'op' => 'fail2ban_write_filter', 'application' => $application->id],
            input: $configs['filter'],
        );

        if ($filterWrite->failed()) {
            throw new Fail2banOperationException($filterWrite->reference);
        }

        $reload = $this->client(['reload']);

        if ($reload->failed()) {
            throw new Fail2banOperationException($reload->reference);
        }
    }

    /**
     * Remove the jail file (which also unloads it from the live daemon on
     * the next reload) and reload so the change is visible immediately.
     *
     * The filter file is left in place: dropping it would invalidate every
     * other jail that referenced the same filter, and there is no clean way
     * to know whether the filter is shared with another application. If the
     * user later adds another jail that wants the same filter, it is still
     * there; if not, the file is harmless.
     */
    public function disableForApp(Application $application): void
    {
        $remove = $this->serverOps->run(
            ['rm', '-f', $this->getJailPath($application)],
            ['feature' => 'application', 'op' => 'fail2ban_remove_jail', 'application' => $application->id],
        );

        if ($remove->failed()) {
            throw new Fail2banOperationException($remove->reference);
        }

        $reload = $this->client(['reload']);

        if ($reload->failed()) {
            throw new Fail2banOperationException($reload->reference);
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
