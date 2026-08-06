<?php

namespace App\Services\Server\Applications;

use App\Enums\AiBotPolicy;
use App\Models\Application;
use App\Services\Server\ServerOps;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Support\Carbon;

/**
 * Which bots actually hit this site, and which of them the current settings
 * would have blocked.
 *
 * The point is to turn the Bot Blocker from a blind choice into an evidenced
 * one: "block AI training bots" means nothing until you can see that GPTBot
 * made 4,000 requests last week and PerplexityBot sent you eleven.
 *
 * Three states, and they must never be confused with each other:
 *
 *  - `unavailable` — the log could not be read at all. Reporting this as "no
 *    bots visited you" would be the panel inventing a fact, and the user
 *    would make a real decision on it.
 *  - `empty` — the log was read and holds no bot hits. A genuinely quiet site.
 *  - `partial` — the log was read up to the scan cap. The counts are a floor,
 *    not a total, and the caller is told so rather than left to assume.
 *
 * Read-only, and it never takes a path from the client: the access log is
 * resolved from the web-server driver, same as the log viewer.
 */
class BotTrafficReporter
{
    /**
     * How many log lines to scan. A busy site's access log runs to millions
     * of lines; reading all of them to count crawlers would cost more than
     * the answer is worth, so the cap is explicit and the response says when
     * it was hit rather than quietly presenting a partial count as a total.
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

        $path = $this->webServers->driver()->logPaths($application)['access'] ?? null;

        if ($path === null) {
            return $this->unavailable($days);
        }

        $result = $this->serverOps->run(
            ['tail', '-n', (string) self::SCAN_LINES, $path],
            ['feature' => 'application', 'op' => 'bot_traffic_read', 'application' => $application->id],
            timeout: 60,
        );

        if ($result->failed()) {
            return $this->unavailable($days);
        }

        $lines = $this->split($result->output());
        $since = now()->subDays($days);

        $counts = [];
        $lastSeen = [];
        $scanned = 0;

        foreach ($lines as $line) {
            $agent = $this->userAgent($line);

            if ($agent === null) {
                continue;
            }

            $stamp = $this->timestamp($line);

            // A line we cannot date is counted rather than dropped: the
            // window is a filter on top of the scan, and silently discarding
            // traffic because one log format differs would understate the
            // very thing this screen exists to show.
            if ($stamp !== null && $stamp->lt($since)) {
                continue;
            }

            $scanned++;

            $bot = $this->match($application, $agent);

            if ($bot === null) {
                continue;
            }

            $counts[$bot] = ($counts[$bot] ?? 0) + 1;

            if ($stamp !== null && (! isset($lastSeen[$bot]) || $stamp->gt($lastSeen[$bot]))) {
                $lastSeen[$bot] = $stamp;
            }
        }

        $blockedNow = $this->blockedNow($application);

        $bots = [];

        foreach ($counts as $name => $hits) {
            $bots[] = [
                'bot' => $name,
                'hits' => $hits,
                'category' => $this->categoryOf($name),
                // What the *current* settings would do to it, so the screen
                // can show "this one is getting through" next to the count
                // rather than making the user cross-reference two lists.
                'blocked' => in_array(mb_strtolower($name), $blockedNow, true),
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
                'blocked_hits' => array_sum(array_map(
                    fn (array $bot) => $bot['blocked'] ? $bot['hits'] : 0,
                    $bots,
                )),
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
     * Every bot name to look for: the shipped list, from the same config the
     * vhost is rendered from — so the frontend can join these counts against
     * `GET /ai-bot-policies` without either side carrying its own list — plus
     * this site's own rules.
     *
     * The site's own agents have to be in here or the feature contradicts
     * itself: someone who adds `SemrushBot` because it is hammering the site
     * would then find it missing from the one screen that shows whether the
     * block is working.
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
            $application->botRules->pluck('value')->all(),
        )));
    }

    private function categoryOf(string $bot): string
    {
        foreach (['training', 'search', 'agent'] as $bucket) {
            foreach ((array) config('ai_bots.'.$bucket) as $known) {
                if (mb_strtolower($known) === mb_strtolower($bot)) {
                    return $bucket;
                }
            }
        }

        return 'custom';
    }

    /**
     * The names the site's current settings would 403, lower-cased for
     * comparison — policy list plus custom blocks, minus exemptions, the same
     * resolution the vhost renderer performs.
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
     * Which known bot a user-agent string belongs to, if any. Matched as a
     * case-insensitive substring, because a real agent carries a version and
     * a URL around the name (`compatible; GPTBot/1.3; +https://…`).
     */
    private function match(Application $application, string $agent): ?string
    {
        $lower = mb_strtolower($agent);

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
}
