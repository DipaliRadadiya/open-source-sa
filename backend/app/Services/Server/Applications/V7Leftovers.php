<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Services\Server\ServerOps;
use App\Services\Server\WebServers\SharedConfigFile;
use App\Services\Server\WebServers\WebServerManager;

/**
 * v7's own config files for one site's AI bot blocker, 7G/8G firewall or
 * (Apache) force HTTPS, taken away the first time the owner changes that
 * setting in v8 (Dipali, 2026-10-13: on change only, no bulk cleanup).
 *
 * v8 loads the site's `conf/` folder as v7 does and writes its own rules
 * there under v7's names (2026-11-11), but never over a file it did not write.
 * So v7's file keeps working until the owner changes the setting; then it is
 * moved aside here, first, and v8's takes its place — or, turned off, none.
 * The panel's own files (its marker on the first line) are never touched here.
 *
 * Moved, never deleted: into a backup folder, so a config test that fails
 * afterwards can put them back. A file goes only when it is at the exact
 * path v7 used for THIS site, is a regular file in a real directory (no
 * links), and carries v7's own marker for this site — a user's own file of
 * the same name stays.
 */
class V7Leftovers
{
    public function __construct(
        private ServerOps $serverOps,
        private WebServerManager $webServers,
    ) {}

    /**
     * Move this site's v7 files for `ai_bot_blocker`, `firewall` or
     * `force_https` aside.
     *
     * @return array<int, array{from: string, to: string}> what was moved, for restore()
     */
    public function remove(Application $application, string $feature): array
    {
        $moved = [];
        $backup = rtrim((string) config('server.v7_leftovers_backup_dir', '/var/lib/panel/v7-leftovers'), '/')
            .'/'.$this->slug($application).'/'.now()->format('Ymd-His');

        foreach ($this->candidates($application, $feature) as $path => $isV7) {
            if (! $this->isPlainFile($application, $path)) {
                continue;
            }

            $read = $this->serverOps->probe(['cat', '--', $path], $this->context($application, 'v7_leftover_read'));

            if (! $read->ok || str_starts_with($read->output(), SharedConfigFile::MARKER) || ! $isV7($read->output())) {
                continue;
            }

            $this->serverOps->run(['mkdir', '-p', $backup], $this->context($application, 'v7_leftover_backup_dir'));
            $to = $backup.'/'.trim(str_replace('/', '__', $path), '_');
            $mv = $this->serverOps->run(['mv', '--', $path, $to], $this->context($application, 'v7_leftover_move'));

            if ($mv->ok) {
                $moved[] = ['from' => $path, 'to' => $to];
            }
        }

        return $moved;
    }

    /** @param  array<int, array{from: string, to: string}>  $moved */
    public function restore(Application $application, array $moved): void
    {
        foreach ($moved as $file) {
            $this->serverOps->run(['mv', '--', $file['to'], $file['from']], $this->context($application, 'v7_leftover_restore'));
        }
    }

    /**
     * Of what remove() moved, the files that were not in the site's conf
     * folder — nginx's conf.d maps and snippets, which other config can lean
     * on and which the panel never writes in their place.
     *
     * @param  array<int, array{from: string, to: string}>  $moved
     * @return array<int, array{from: string, to: string}>
     */
    public function outsideSiteConf(Application $application, array $moved): array
    {
        $driver = $this->webServers->driver();

        return array_values(array_filter($moved, fn (array $file) => ! $driver->isSiteConfPath($application, $file['from'])));
    }

    /**
     * The paths v7 wrote for this site on this web server, each with a check
     * that the contents are v7's (from v7's own templates).
     *
     * @return array<string, callable(string): bool>
     */
    public function candidates(Application $application, string $feature): array
    {
        $slug = $this->slug($application);
        // v7's `toSafeUnderscore()`: what its map variables and snippet names use.
        $safe = trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $slug), '_');
        $root = $application->rootPath();
        $driver = $this->webServers->driver()->name();
        $rules = fn (string $content): bool => str_contains($content, 'YandexBot') && str_contains($content, '[F,L]');

        if ($feature === 'ai_bot_blocker') {
            $mapsThis = fn (string $content): bool => str_contains($content, "{$safe}_block_ai");

            return match ($driver) {
                'nginx' => [
                    "/etc/nginx/conf.d/{$slug}-ai-bot-blocker.conf" => $mapsThis,
                    "{$root}/conf/nginx/ai-bot-blocker.conf" => $mapsThis,
                ],
                // `conf/apache`, as on a real v7 server — not `apache2`, which
                // never matched one (found 2026-11-11).
                'apache' => ["{$root}/conf/apache/ai-bot-blocker.conf" => $rules],
                'openlitespeed' => ["{$root}/conf/openlitespeed/rewrites/ai-bot-blocker.conf" => $rules],
                default => [],
            };
        }

        if ($feature === 'force_https') {
            // v7's Apache forceHttps.conf; nginx and OpenLiteSpeed redirect in
            // the vhost itself, on v7 as here.
            return $driver === 'apache'
                ? ["{$root}/conf/apache/forceHttps.conf" => fn (string $content): bool => str_contains($content, 'RewriteCond %{HTTPS} !=on')]
                : [];
        }

        $files = [];
        foreach (['7g', '8g'] as $g) {
            $perishable = fn (string $content): bool => str_contains($content, "perishablepress.com/{$g}-firewall");
            $forThisSite = fn (string $content): bool => str_contains($content, "_{$safe}") || str_contains($content, "{$g}-{$safe}.conf");

            $files += match ($driver) {
                'nginx' => [
                    "/etc/nginx/conf.d/{$g}-firewall-{$safe}.conf" => $forThisSite,
                    "/etc/nginx/snippets/{$g}-{$safe}.conf" => $forThisSite,
                    "{$root}/conf/nginx/{$g}-firewall.conf" => $forThisSite,
                ],
                'apache' => ["{$root}/conf/apache/{$g}-firewall.conf" => $perishable],
                'openlitespeed' => ["{$root}/conf/openlitespeed/rewrites/{$g}-firewall.conf" => $perishable],
                default => [],
            };
        }

        return $files;
    }

    /** A regular file, in a directory that is a directory — never through a link. */
    private function isPlainFile(Application $application, string $path): bool
    {
        $file = $this->serverOps->probe(['stat', '-c', '%F', '--', $path], $this->context($application, 'v7_leftover_stat'));
        $dir = $this->serverOps->probe(['stat', '-c', '%F', '--', dirname($path)], $this->context($application, 'v7_leftover_stat'));

        return $file->ok && trim($file->output()) === 'regular file'
            && $dir->ok && trim($dir->output()) === 'directory';
    }

    private function slug(Application $application): string
    {
        return (string) ($application->slug ?: $application->name);
    }

    /** @return array<string, mixed> */
    private function context(Application $application, string $op): array
    {
        return ['feature' => 'application', 'op' => $op, 'application' => $application->id];
    }
}
