<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\Fail2banOperationException;
use App\Models\Application;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Support\Str;

/**
 * Per-application fail2ban — a different feature from the server-level
 * `Fail2banManager`, not a filtered view of it: this watches one site's own
 * access log, which only exists once the application does. Same
 * conventions as the server-level manager: no DB table beyond the one
 * `fail2ban_enabled` column, live state read back from `fail2ban-client`
 * rather than assumed, `reload` never `restart` (a restart forgets active
 * bans), and fully independent of the Firewall/UFW feature.
 *
 * One shared drop-in file, regenerated from every enabled application —
 * the same shape `Fail2banManager::write()` uses for the server-level
 * jails, just keyed by application instead of by a fixed jail list.
 */
class ApplicationFail2banManager
{
    public function __construct(
        private ServerOps $serverOps,
        private WebServerManager $webServers,
    ) {}

    /**
     * @return array<int, string>
     */
    public function jailNames(Application $application): array
    {
        $names = ["app-{$application->id}-generic"];

        if ($application->site_type === 'wordpress') {
            $names[] = "app-{$application->id}-wplogin";
        }

        return $names;
    }

    /**
     * Live status for one application's jail(s) — enabled state comes from
     * the column, everything else (banned IPs, counters) is read live.
     *
     * @return array<int, array<string, mixed>>
     */
    public function status(Application $application): array
    {
        $active = $this->activeJails();

        return array_map(function (string $jail) use ($active) {
            $enabled = in_array($jail, $active, true);

            return [
                'jail' => $jail,
                'enabled' => $enabled,
                'banned' => $enabled ? $this->bannedIn($jail) : [],
                'stats' => $enabled ? $this->stats($jail) : null,
            ];
        }, $this->jailNames($application));
    }

    /**
     * @return array<string, int>
     */
    private function stats(string $jail): array
    {
        $output = $this->client(['status', $jail])->output();

        $number = fn (string $label): int => preg_match('/'.preg_quote($label, '/').':\s*(\d+)/i', $output, $m) === 1 ? (int) $m[1] : 0;

        return [
            'currently_failed' => $number('Currently failed'),
            'total_failed' => $number('Total failed'),
            'currently_banned' => $number('Currently banned'),
            'total_banned' => $number('Total banned'),
        ];
    }

    /**
     * Bans the generic jail — the one every enabled application has,
     * regardless of type.
     */
    public function ban(Application $application, string $ip): void
    {
        $jail = $this->jailNames($application)[0];

        if (! in_array($jail, $this->activeJails(), true)) {
            throw new Fail2banOperationException((string) Str::uuid());
        }

        $result = $this->client(['set', $jail, 'banip', $ip]);

        if ($result->failed()) {
            throw new Fail2banOperationException($result->reference);
        }
    }

    /**
     * Unban from whichever of this application's jails currently holds the
     * address — the same "unban means unban, not unban-from-the-one-jail-
     * you-happened-to-look-at" reasoning the server-level manager uses.
     */
    public function unban(Application $application, string $ip): void
    {
        $released = false;

        foreach ($this->jailNames($application) as $jail) {
            if (in_array($ip, $this->bannedIn($jail), true) && $this->client(['set', $jail, 'unbanip', $ip])->ok) {
                $released = true;
            }
        }

        if (! $released) {
            throw new Fail2banOperationException((string) Str::uuid());
        }
    }

    /**
     * Regenerate the shared drop-in from every enabled application and
     * reload. Called after any single application's toggle changes — cheap,
     * and it means the file on disk can never drift from the database.
     */
    public function sync(): void
    {
        $this->ensureFilters();

        $body = "# Managed by the control panel — do not edit by hand\n";

        foreach (Application::where('fail2ban_enabled', true)->get() as $application) {
            $body .= $this->renderJails($application);
        }

        $result = $this->serverOps->run(
            ['tee', $this->dropInPath()],
            ['feature' => 'application', 'op' => 'fail2ban_write_config'],
            input: $body,
        );

        if ($result->failed()) {
            throw new Fail2banOperationException($result->reference);
        }

        $this->reload();
    }

    private function renderJails(Application $application): string
    {
        $logPath = $this->webServers->driver()->logPaths($application)['access'] ?? null;

        if ($logPath === null) {
            return '';
        }

        $jails = (array) config('server.fail2ban_apps.jails');
        $body = "\n[app-{$application->id}-generic]\n"
            .'enabled = true'."\n"
            ."filter = {$jails['generic']['filter']}\n"
            ."logpath = {$logPath}\n"
            ."bantime = {$jails['generic']['bantime']}\n"
            ."findtime = {$jails['generic']['findtime']}\n"
            ."maxretry = {$jails['generic']['maxretry']}\n"
            // systemd is not the source here — an application's access log
            // is a plain file the web server writes, not a journal unit.
            ."backend = auto\n";

        if ($application->site_type === 'wordpress') {
            $body .= "\n[app-{$application->id}-wplogin]\n"
                .'enabled = true'."\n"
                ."filter = {$jails['wordpress']['filter']}\n"
                ."logpath = {$logPath}\n"
                ."bantime = {$jails['wordpress']['bantime']}\n"
                ."findtime = {$jails['wordpress']['findtime']}\n"
                ."maxretry = {$jails['wordpress']['maxretry']}\n"
                ."backend = auto\n";
        }

        return $body;
    }

    /**
     * Ship the two filter definitions once — static content, safe to
     * overwrite unconditionally on every sync, the same reasoning
     * `Waf8GManager::ensureSharedMaps()` uses for its own shared file.
     */
    private function ensureFilters(): void
    {
        $directory = rtrim((string) config('server.fail2ban_apps.filter_d'), '/');

        foreach (['panel-app-generic', 'panel-app-wplogin'] as $filter) {
            $contents = file_get_contents(resource_path("fail2ban/{$filter}.conf"));

            if ($contents === false) {
                continue;
            }

            $this->serverOps->run(
                ['tee', "{$directory}/{$filter}.conf"],
                ['feature' => 'application', 'op' => 'fail2ban_write_filter'],
                input: $contents,
            );
        }
    }

    public function reload(): void
    {
        $result = $this->client(['reload']);

        if ($result->failed()) {
            throw new Fail2banOperationException($result->reference);
        }
    }

    /**
     * @return array<int, string>
     */
    private function activeJails(): array
    {
        $output = $this->client(['status'])->output();

        if (preg_match('/Jail list:\s*(.*)$/m', $output, $m) !== 1) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $m[1]))));
    }

    /**
     * @return array<int, string>
     */
    private function bannedIn(string $jail): array
    {
        $output = $this->client(['get', $jail, 'banned'])->output();

        preg_match_all("/'([^']+)'/", $output, $matches);

        return $matches[1] ?? [];
    }

    private function dropInPath(): string
    {
        return rtrim((string) config('server.fail2ban_apps.jail_d'), '/')
            .'/'.(string) config('server.fail2ban_apps.drop_in');
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
