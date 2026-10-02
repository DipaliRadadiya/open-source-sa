<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A user-agent token the panel will put into a web-server config file.
 *
 * Two separate concerns, and both are refusals rather than sanitisation:
 *
 * 1. **Config injection.** The value is joined into a regex inside an nginx
 *    `if`, an Apache `SetEnvIfNoCase` or an OLS rewrite, all written by an
 *    elevated process. A newline, a quote or a brace ends the directive early
 *    and the rest of the line becomes configuration. The charset allowlist is
 *    the fix; `preg_quote` at render time only covers the regex half.
 *
 * 2. **Catch-alls that quietly deindex the site.** The pattern is matched
 *    case-insensitively against the start of the user agent, so `bot` matches
 *    `Googlebot` and `bingbot`. A widely-copied nginx "block AI bots" snippet
 *    has exactly this bug. Someone typing `bot` means "block bots" and gets
 *    "disappear from search" with nothing in the panel to explain it, so the
 *    short and generic values are refused by name.
 */
class BotUserAgent implements ValidationRule
{
    /** `$blocking`: a value that will block. An allow entry can name a browser harmlessly. */
    public function __construct(private bool $blocking = false) {}

    /**
     * Values that match a legitimate search crawler, or everything. Compared
     * case-insensitively against the whole value, not as substrings — the
     * point is to catch a value that is *only* a generic word.
     */
    private const CATCH_ALLS = [
        'bot', 'bots', 'crawler', 'crawl', 'spider', 'agent', 'search',
        '*', '.*', '.', 'a', 'mozilla', 'http', 'www',
    ];

    /** Blocking these is never what the user meant by "block bots". */
    private const SEARCH_ENGINES = [
        'googlebot', 'google', 'bingbot', 'bing', 'duckduckbot',
        'yandexbot', 'baiduspider', 'slurp', 'applebot',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('errors/application.bot_agent_invalid')->translate();

            return;
        }

        $trimmed = trim($value);

        // Letters, digits and the punctuation real crawler tokens use
        // (`Google-Extended`, `SemrushBot-OCOB`, `anthropic-ai`, `GPTBot/1.3`).
        // Deliberately no whitespace, quotes, braces, backslashes or newlines.
        if (preg_match('/^[A-Za-z0-9._\-\/]{2,100}$/', $trimmed) !== 1) {
            $fail('errors/application.bot_agent_invalid')->translate();

            return;
        }

        $lower = mb_strtolower($trimmed);

        if (in_array($lower, self::CATCH_ALLS, true)) {
            $fail('errors/application.bot_agent_too_broad')->translate();

            return;
        }

        // A robots.txt-only token (`Google-Extended`, `Applebot-Extended`) is
        // never sent as a user agent, so a block on it would do nothing while
        // looking like it worked.
        if (in_array($lower, array_map('mb_strtolower', (array) config('ai_bots.robots_only')), true)) {
            $fail('errors/application.bot_agent_robots_only')->translate();

            return;
        }

        // Whole value, not a prefix: `applebot` is a search engine, and a
        // longer name that starts with it is a different agent.
        if (in_array($lower, self::SEARCH_ENGINES, true)) {
            $fail('errors/application.bot_agent_search_engine')->translate();

            return;
        }

        // Bug #85: matched anywhere in the user agent, `Chrome`, `Android` or
        // `Googlebot/2.1` blocked every real visitor or Google. Asked of the
        // real user agents rather than a list of words, so every spelling of
        // the mistake is caught.
        if ($this->blocking) {
            foreach ((array) config('ai_bots.real_visitors') as $who => $userAgent) {
                if (mb_stripos((string) $userAgent, $trimmed) !== false) {
                    $fail('errors/application.bot_agent_matches_visitors')->translate(['agent' => $who]);

                    return;
                }
            }
        }
    }
}
