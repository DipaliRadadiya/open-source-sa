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

        // `applebot` is a search engine, but `Applebot-Extended` is the
        // training opt-out token and blocking it is legitimate — so this
        // compares the whole value rather than a prefix.
        if (in_array($lower, self::SEARCH_ENGINES, true)) {
            $fail('errors/application.bot_agent_search_engine')->translate();
        }
    }
}
