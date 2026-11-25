<?php

namespace App\Services\Server\Waf;

use RuntimeException;

/**
 * The 8G ruleset as OpenLiteSpeed rewrite conditions, by category.
 *
 * Read from resources/waf/8g-apache-setenvif.conf (already split into the panel's six
 * categories) rather than kept as a third copy. OLS implements mod_rewrite, so each line maps to
 * one `RewriteCond` with the same variable and pattern, and an 8G update reaches all three web
 * servers together.
 *
 * That file has only two line shapes; anything else throws rather than being skipped:
 *
 *   SetEnvIfExpr "%{QUERY_STRING} =~ m#PATTERN#i" waf_query
 *   SetEnvIfNoCase <Attribute> PATTERN waf_<category>
 *
 * OLS takes the patterns as PCRE (lookbehind works) and supports `E=` flags and `%{ENV:...}`,
 * which is how exceptions skip the block.
 */
class OlsWafRuleset
{
    /** SetEnvIf attribute => rewrite variable. */
    private const VARIABLES = [
        'Request_URI' => 'REQUEST_URI',
        'User-Agent' => 'HTTP_USER_AGENT',
        'Referer' => 'HTTP_REFERER',
        'Cookie' => 'HTTP_COOKIE',
        'Request_Method' => 'REQUEST_METHOD',
    ];

    /** env var in the Apache file => the panel's category key. */
    private const CATEGORIES = [
        'waf_query' => 'query_string',
        'waf_uri' => 'request_uri',
        'waf_agent' => 'user_agent',
        'waf_referer' => 'referrer',
        'waf_cookie' => 'cookie',
        'waf_method' => 'method',
    ];

    /** @var array<string, array<int, array{0: string, 1: string}>>|null */
    private ?array $rules = null;

    /**
     * Apache's config-argument unescaping (httpd `substring_conf`): a backslash before another
     * backslash, or before the quote of a quoted argument, is dropped; all others are kept.
     */
    public static function apacheUnescape(string $value, ?string $quote): string
    {
        $out = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            if ($value[$i] === '\\' && $i + 1 < $length && ($value[$i + 1] === '\\' || ($quote !== null && $value[$i + 1] === $quote))) {
                $out .= $value[++$i];

                continue;
            }

            $out .= $value[$i];
        }

        return $out;
    }

    /**
     * `[variable, pattern]` pairs for each enabled category, in file order.
     *
     * @param  array<int, string>  $categories
     * @return array<string, array<int, array{0: string, 1: string}>>
     */
    public function for(array $categories): array
    {
        return array_intersect_key($this->all(), array_flip($categories));
    }

    /**
     * @return array<string, array<int, array{0: string, 1: string}>>
     */
    public function all(): array
    {
        return $this->rules ??= $this->parse((string) file_get_contents(resource_path('waf/8g-apache-setenvif.conf')));
    }

    /**
     * @return array<string, array<int, array{0: string, 1: string}>>
     */
    public function parse(string $file): array
    {
        $rules = [];

        foreach (preg_split('/\r?\n/', $file) ?: [] as $number => $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Apache's parser turns `\\` into `\` (and `\"` into `"` when quoted), but OLS reads a
            // condition raw, so write the regex as Apache would see it. Otherwise `=?\\\(?:...`
            // reaches OLS as an unbalanced group and it silently drops the site's whole rewrite block.
            if (preg_match('/^SetEnvIfExpr "%\{QUERY_STRING\} =~ m#(.*)#i" (waf_[a-z]+)$/', $line, $m) === 1) {
                [$variable, $pattern, $env] = ['QUERY_STRING', self::apacheUnescape($m[1], '"'), $m[2]];
            } elseif (preg_match('/^SetEnvIfNoCase (\S+) (\S+) (waf_[a-z]+)$/', $line, $m) === 1 && isset(self::VARIABLES[$m[1]])) {
                [$variable, $pattern, $env] = [self::VARIABLES[$m[1]], self::apacheUnescape($m[2], null), $m[3]];
            } else {
                throw new RuntimeException('8G line '.($number + 1).' has a shape the OLS ruleset does not know: '.$line);
            }

            // Compiled before anything is written: OLS's `-t` does not check rewrite regexes, so a
            // bad one would just silently stop every rule applying.
            if (@preg_match('#'.str_replace('#', '\\#', $pattern).'#i', '') === false) {
                throw new RuntimeException('8G line '.($number + 1).' is not a valid regex once unescaped: '.$pattern);
            }

            $category = self::CATEGORIES[$env] ?? throw new RuntimeException('8G line '.($number + 1)." sets an unknown category {$env}");
            // Always grouped: a RewriteCond pattern *starting* with `<`, `>`, `=`, `-` or `!` is read
            // as an operator (the cookie rule `<|>|...` became "less than" and blocked every request).
            // `(?:...)` matches the same and can never be read as one.
            $rules[$category][] = [$variable, '(?:'.$pattern.')'];
        }

        return $rules;
    }
}
