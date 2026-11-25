<?php

namespace App\Services\Server\Applications;

use App\Enums\AiBotPolicy;
use App\Models\Application;
use App\Services\Server\ServerOps;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Support\Carbon;

/**
 * Which bots actually hit this site, and which of them the current settings would have blocked.
 *
 * Three states, never to be confused:
 *
 *  - `unavailable` — the log could not be read. Reporting "no bots" here would be an invented fact.
 *  - `empty` — the log was read and holds no bot hits.
 *  - `partial` — the log was read up to the scan cap; the counts are a floor, not a total.
 *
 * Read-only, and it never takes a path from the client: the access log comes from the
 * web-server driver, same as the log viewer.
 */
class BotTrafficReporter
{
    /**
     * How many log lines to scan. A busy log runs to millions of lines; the response says
     * (`partial`) when the cap was hit.
     */
    public const SCAN_LINES = 200000;

    public const DEFAULT_DAYS = 7;

    public const MAX_DAYS = 90;

    public function __construct(
        private ServerOps $serverOps,
        private WebServerManager $webServers,
    ) {}

    /**
     * @return array{status: string, days: int, scanned_lines: int, since: ?string, bots: array<int, array<string, mixed>>, totals: array<string, int>}
     */
    public function report(Application $application, int $days = self::DEFAULT_DAYS): array
    {
        $days = max(1, min($days, self::MAX_DAYS));

        $logs = $this->webServers->driver()->logPaths($application);
        // HTTP and HTTPS: with HTTPS forced, nearly all traffic is in the `-ssl` file.
        $paths = array_values(array_filter([$logs['access'] ?? null, $logs['access_ssl'] ?? null]));

        if ($paths === []) {
            return $this->unavailable($days);
        }

        $lines = [];
        $read = false;

        foreach ($paths as $path) {
            $result = $this->serverOps->run(
                ['tail', '-n', (string) self::SCAN_LINES, $path],
                ['feature' => 'application', 'op' => 'bot_traffic_read', 'application' => $application->id],
                timeout: 60,
            );

            // A missing `-ssl` file is a site that never had HTTPS, not a failure.
            if ($result->ok) {
                $read = true;
                $lines = array_merge($lines, $this->split($result->output()));
            }
        }

        if (! $read) {
            return $this->unavailable($days);
        }
        $since = now()->subDays($days);

        $counts = [];
        $refused = [];
        $lastSeen = [];
        $lastStatus = [];
        $scanned = 0;

        foreach ($lines as $line) {
            $agent = $this->userAgent($line);

            if ($agent === null) {
                continue;
            }

            $stamp = $this->timestamp($line);

            // A line we cannot date is counted, not dropped: dropping it would understate traffic.
            if ($stamp !== null && $stamp->lt($since)) {
                continue;
            }

            $scanned++;

            $bot = $this->match($application, $agent);

            if ($bot === null) {
                continue;
            }

            $counts[$bot] = ($counts[$bot] ?? 0) + 1;

            // What the server actually answered, from the log line, not from the current settings.
            $status = $this->status($line);

            if ($status === 403) {
                $refused[$bot] = ($refused[$bot] ?? 0) + 1;
            }

            if ($stamp !== null && (! isset($lastSeen[$bot]) || $stamp->gt($lastSeen[$bot]))) {
                $lastSeen[$bot] = $stamp;
                $lastStatus[$bot] = $status;
            } elseif (! array_key_exists($bot, $lastStatus)) {
                $lastStatus[$bot] = $status;
            }
        }

        $blockedNow = $this->blockedNow($application);

        $bots = [];

        foreach ($counts as $name => $hits) {
            $bots[] = [
                'bot' => $name,
                'hits' => $hits,
                'category' => $this->categoryOf($name, $application),
                // What the *current* settings would do to it.
                'blocked' => in_array(mb_strtolower($name), $blockedNow, true),
                // Real refusals in the window, and what the latest hit got.
                'blocked_hits' => $refused[$name] ?? 0,
                'last_status' => $lastStatus[$name] ?? null,
                'last_seen' => $lastSeen[$name]?->format('d-m-Y H:i:s'),
                'last_seen_human' => $lastSeen[$name]?->diffForHumans(),
            ];
        }

        usort($bots, fn (array $a, array $b) => $b['hits'] <=> $a['hits']);

        return [
            'status' => count($lines) >= self::SCAN_LINES ? 'partial' : ($bots === [] ? 'empty' : 'ok'),
            'days' => $days,
            'scanned_lines' => $scanned,
            'since' => $since->format('d-m-Y H:i:s'),
            'bots' => $bots,
            'totals' => [
                'bots' => count($bots),
                'hits' => array_sum($counts),
                'blocked_hits' => array_sum($refused),
            ],
        ];
    }

    /**
     * @return array{status: string, days: int, scanned_lines: int, since: ?string, bots: array<int, array<string, mixed>>, totals: array<string, int>}
     */
    private function unavailable(int $days): array
    {
        return [
            'status' => 'unavailable',
            'days' => $days,
            'scanned_lines' => 0,
            'since' => null,
            'bots' => [],
            'totals' => ['bots' => 0, 'hits' => 0, 'blocked_hits' => 0],
        ];
    }

    /**
     * Every bot name to look for: the shipped list (same config the vhost is rendered from) plus
     * this site's own rules, so a bot the owner added still shows up on the traffic screen.
     *
     * @return array<int, string>
     */
    private function known(Application $application): array
    {
        $application->loadMissing('botRules');

        return array_values(array_unique(array_merge(
            (array) config('ai_bots.training'),
            (array) config('ai_bots.search'),
            (array) config('ai_bots.agent'),
            $application->ai_bot_policy === AiBotPolicy::LegacyV7 ? (array) config('ai_bots_v7') : [],
            $application->botRules->pluck('value')->all(),
        )));
    }

    /**
     * Why a bot is on this site's list: one of v8's own buckets, the site's own rule, or v7's
     * list on a legacy_v7 site. A name the owner added stays `custom`.
     */
    private function categoryOf(string $bot, Application $application): string
    {
        $lower = mb_strtolower($bot);

        foreach (['training', 'search', 'agent'] as $bucket) {
            foreach ((array) config('ai_bots.'.$bucket) as $known) {
                if (mb_strtolower($known) === $lower) {
                    return $bucket;
                }
            }
        }

        $ownRule = $application->botRules->contains(fn ($rule) => mb_strtolower((string) $rule->value) === $lower);

        if (! $ownRule && $application->ai_bot_policy === AiBotPolicy::LegacyV7
            && in_array($lower, array_map(mb_strtolower(...), (array) config('ai_bots_v7')), true)) {
            return 'legacy';
        }

        return 'custom';
    }

    /**
     * The names the current settings would 403, lower-cased: policy list plus custom blocks,
     * minus exemptions (same resolution as the vhost renderer).
     *
     * @return array<int, string>
     */
    private function blockedNow(Application $application): array
    {
        $application->loadMissing('botRules');

        $bots = $application->ai_bot_policy instanceof AiBotPolicy
            ? $application->ai_bot_policy->blockedBots()
            : [];

        $bots = array_merge($bots, $application->botRules->where('type', 'block')->pluck('value')->all());

        $allowed = $application->botRules->where('type', 'allow')
            ->map(fn ($rule) => mb_strtolower((string) $rule->value))
            ->all();

        return array_values(array_diff(
            array_unique(array_map(mb_strtolower(...), $bots)),
            $allowed,
        ));
    }

    /**
     * Which known bot a user-agent string belongs to, if any: a case-insensitive substring,
     * since real agents wrap the name in a version and URL (`compatible; GPTBot/1.3; +https://…`).
     */
    private function match(Application $application, string $agent): ?string
    {
        $lower = mb_strtolower($agent);

        if ($application->ai_bot_policy === AiBotPolicy::LegacyV7 && str_contains($lower, 'yandexbot')) {
            return null;
        }

        foreach ($this->known($application) as $bot) {
            if (str_contains($lower, mb_strtolower($bot))) {
                return $bot;
            }
        }

        return null;
    }

    /** The quoted user agent, which is the last quoted field of a combined-format line. */
    private function userAgent(string $line): ?string
    {
        if (preg_match_all('/"([^"]*)"/', $line, $matches) < 1) {
            return null;
        }

        $agent = end($matches[1]);

        return $agent === '' || $agent === '-' ? null : $agent;
    }

    /** The `[10/Aug/2026:13:55:36 +0000]` stamp both nginx and Apache write. */
    private function timestamp(string $line): ?Carbon
    {
        if (preg_match('/\[(\d{2}\/\w{3}\/\d{4}:\d{2}:\d{2}:\d{2}\s[+\-]\d{4})\]/', $line, $matches) !== 1) {
            return null;
        }

        try {
            return Carbon::createFromFormat('d/M/Y:H:i:s O', $matches[1]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, string>
     */
    private function split(string $content): array
    {
        $content = rtrim(str_replace("\r\n", "\n", $content), "\n");

        return $content === '' ? [] : explode("\n", $content);
    }

    /**
     * The status code of a combined/common-format log line (right after the quoted request).
     */
    private function status(string $line): ?int
    {
        return preg_match('/"[^"]*" (\d{3}) /', $line, $match) === 1 ? (int) $match[1] : null;
    }
}
