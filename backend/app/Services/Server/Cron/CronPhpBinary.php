<?php

namespace App\Services\Server\Cron;

use App\Models\Application;
use App\Models\Cronjob;
use App\Services\Server\Runtimes\PhpRuntime;

/**
 * Which PHP a cron command runs, and the command with that PHP swapped.
 *
 * A command names PHP one of two ways. A fixed version — what the panel's
 * installers write, `/usr/bin/php8.2 -f …/cron.php` — keeps running on that
 * version after its site moves to another. Plain `php` runs whatever the
 * server default is. Neither tells the user anything when the site's PHP
 * changes, and #5 (support list, 2026-10-27) is to say so and let them choose.
 *
 * Only the panel's own interpreter paths are recognised, on every stack:
 *
 *   /usr/bin/php8.2                          FPM stacks (and bare `php8.2`)
 *   /usr/local/lsws/lsphp82/bin/php|lsphp    OpenLiteSpeed
 *   /usr/bin/lsphp8.2, /usr/local/bin/lsphp8.2
 *   php, /usr/bin/php, /usr/local/bin/php    the server default
 *
 * Anything else is "unknown" and is never rewritten: a guess that rewrites the
 * wrong word in somebody's command is worse than not offering to.
 */
class CronPhpBinary
{
    /** What plain `php` (and its links) answers: the server default. */
    public const DEFAULT = 'default';

    /** Characters that end a shell word around a path. */
    private const EDGE_BEFORE = '(?<=^|[\s;&|()`"\'])';

    private const EDGE_AFTER = '(?=$|[\s;&|()`"\'])';

    private ?string $serverDefault = null;

    private bool $serverDefaultRead = false;

    public function __construct(private PhpRuntime $php) {}

    /**
     * The PHP a command uses: `"8.2"`, {@see DEFAULT}, or null when it names
     * none, or names two different ones (nothing could say which to switch).
     */
    public function version(string $command): ?string
    {
        $found = array_unique(array_column($this->matches($command), 'version'));

        return count($found) === 1 ? $found[0] : null;
    }

    /**
     * The command with its PHP replaced by `$version`'s interpreter, or null
     * when there is nothing it can safely replace.
     *
     * Only the interpreter's own word changes. Every other character of the
     * command — arguments, redirects, a `cd` before it — is left exactly as
     * it was.
     */
    public function switchTo(string $command, string $version): ?string
    {
        if ($this->version($command) === null) {
            return null;
        }

        $target = $this->php->binaryPath($version);
        $result = $command;

        // From the end, so earlier offsets stay valid as lengths change.
        foreach (array_reverse($this->matches($command)) as $match) {
            $result = substr_replace($result, $target, $match['offset'], strlen($match['word']));
        }

        return $result;
    }

    /**
     * The version a command actually runs on today: a fixed version is itself,
     * plain `php` is whatever the server default is right now.
     */
    public function effectiveVersion(string $command): ?string
    {
        $version = $this->version($command);

        return $version === self::DEFAULT ? $this->serverDefault() : $version;
    }

    /**
     * Whether a site's cron runs on the site's PHP. Null when there is
     * nothing to compare: no site, a site with no PHP version, or a command
     * whose PHP is unknown.
     */
    public function matchesSite(Cronjob $cronjob, ?Application $application): ?bool
    {
        $site = $application?->php_version;
        $runs = $this->effectiveVersion((string) $cronjob->command);

        if ($site === null || $site === '' || $runs === null) {
            return null;
        }

        return $runs === $site;
    }

    /**
     * A site's cron jobs that do not run on `$version`, for the user to choose
     * from after changing the site's PHP. Changes nothing.
     *
     * `suggested` is the default tick: yes for a job that names another
     * version (it was written for this site's old PHP and stays there), no for
     * plain `php` (it may follow the server default on purpose). `new_command`
     * is exactly what a switch would write, so nothing is a surprise.
     *
     * @return array<int, array{id: int, name: string, command: string, php_version: string, suggested: bool, new_command: string}>
     */
    public function review(Application $application, string $version): array
    {
        return Cronjob::query()
            ->where('application_id', $application->id)
            ->orderBy('name')
            ->get()
            ->map(function (Cronjob $cronjob) use ($version) {
                $named = $this->version((string) $cronjob->command);

                // Unknown, or already runs on this version. Plain `php` on a
                // server whose default is this version is in step today, so
                // it is not listed either.
                if ($named === null || $this->effectiveVersion((string) $cronjob->command) === $version) {
                    return null;
                }

                return [
                    'id' => $cronjob->id,
                    'name' => $cronjob->name,
                    'command' => $cronjob->command,
                    'php_version' => $named,
                    'suggested' => $named !== self::DEFAULT,
                    'new_command' => (string) $this->switchTo((string) $cronjob->command, $version),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Every cron job that runs plain `php`: they follow the server default,
     * so they move with it. For the warning after the default changes.
     *
     * @return array<int, array{id: int, name: string, command: string, application_id: int|null}>
     */
    public function onServerDefault(): array
    {
        return Cronjob::query()
            ->orderBy('name')
            ->get(['id', 'name', 'command', 'application_id'])
            ->filter(fn (Cronjob $cronjob) => $this->version((string) $cronjob->command) === self::DEFAULT)
            ->map(fn (Cronjob $cronjob) => [
                'id' => $cronjob->id,
                'name' => $cronjob->name,
                'command' => $cronjob->command,
                'application_id' => $cronjob->application_id,
            ])
            ->values()
            ->all();
    }

    /**
     * Names of the cron jobs that name `$version` itself — what removing that
     * version would break.
     *
     * @return array<int, string>
     */
    public function namingVersion(string $version): array
    {
        return Cronjob::query()
            ->orderBy('name')
            ->get(['id', 'name', 'command'])
            ->filter(fn (Cronjob $cronjob) => $this->version((string) $cronjob->command) === $version)
            ->pluck('name')
            ->values()
            ->all();
    }

    /**
     * Read once per request: it can ask the system, and a list asks per row.
     */
    public function serverDefault(): ?string
    {
        if (! $this->serverDefaultRead) {
            $this->serverDefault = $this->php->default();
            $this->serverDefaultRead = true;
        }

        return $this->serverDefault;
    }

    /**
     * Every PHP word in the command.
     *
     * Plain `php` and the default links only count where a command starts —
     * the beginning, or after `;`, `&&`, `||`, `|`, `(` or a backtick — so
     * `echo php` is left alone. A versioned path is specific enough to count
     * anywhere it stands as a whole word.
     *
     * @return array<int, array{word: string, offset: int, version: string}>
     */
    private function matches(string $command): array
    {
        $found = [];

        $versioned = [
            // /usr/bin/php8.2, /usr/local/bin/php8.2, php8.2
            '(?:/usr(?:/local)?/bin/)?php(?<v1>\d+\.\d+)',
            // /usr/bin/lsphp8.2, /usr/local/bin/lsphp8.2
            '/usr(?:/local)?/bin/lsphp(?<v2>\d+\.\d+)',
            // /usr/local/lsws/lsphp82/bin/php (or bin/lsphp)
            '/usr/local/lsws/lsphp(?<v3>\d)(?<v3b>\d+)/bin/(?:ls)?php',
        ];

        foreach ($versioned as $pattern) {
            preg_match_all('#'.self::EDGE_BEFORE.$pattern.self::EDGE_AFTER.'#', $command, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

            foreach ($all as $m) {
                $version = match (true) {
                    isset($m['v1']) => $m['v1'][0],
                    isset($m['v2']) => $m['v2'][0],
                    // LiteSpeed's tree has no dot: lsphp82 is 8.2.
                    default => $m['v3'][0].'.'.$m['v3b'][0],
                };

                $found[$m[0][1]] = ['word' => $m[0][0], 'offset' => $m[0][1], 'version' => $version];
            }
        }

        preg_match_all('#(?:^|[;&|(`])\s*\K(?:/usr(?:/local)?/bin/)?php'.self::EDGE_AFTER.'#', $command, $plain, PREG_OFFSET_CAPTURE);

        foreach ($plain[0] as [$word, $offset]) {
            $found[$offset] = ['word' => $word, 'offset' => $offset, 'version' => self::DEFAULT];
        }

        ksort($found);

        return array_values($found);
    }
}
