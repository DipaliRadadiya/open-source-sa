<?php

namespace App\Services\Server\Waf;

use RuntimeException;

/**
 * The 8G ruleset as OpenLiteSpeed rewrite conditions, by category.
 *
 * Read from the Apache file, not kept as a third copy: resources/waf/
 * 8g-apache-setenvif.conf is already split into the six categories the panel
 * offers, and OLS implements Apache's mod_rewrite, so each line maps to one
 * `RewriteCond` on the same variable with the same pattern. One source means
 * an 8G update reaches all three web servers together.
 *
 * Two line shapes exist in that file, and nothing else (checked when this was
 * written; anything else throws rather than being skipped quietly):
 *
 *   SetEnvIfExpr "%{QUERY_STRING} =~ m#PATTERN#i" waf_query
 *   SetEnvIfNoCase <Attribute> PATTERN waf_<category>
 *
 * Verified on the OLS test server on 2026-09-30: OLS takes the patterns as
 * PCRE (the `(?<!SAML)` lookbehind works) and supports `E=` flags and
 * `%{ENV:...}`, which is how exceptions skip the block.
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
     * Apache's config-argument unescaping (httpd `substring_conf`): a
     * backslash before another backslash, or before the quote character of a
     * quoted argument, is dropped. Every other backslash is kept.
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

            // What the file says is not the regex Apache compiles: its config
            // parser turns `\\` into `\` in every argument, and `\"` into `"`
            // inside a quoted one. OLS reads a condition raw, so the regex has
            // to be written out as Apache would have seen it — otherwise
            // `=?\\\(?:...` reaches OLS as an unbalanced group, which it
            // rejects by dropping the site's whole rewrite block, silently
            // (seen on the OLS test server, 2026-09-30).
            if (preg_match('/^SetEnvIfExpr "%\{QUERY_STRING\} =~ m#(.*)#i" (waf_[a-z]+)$/', $line, $m) === 1) {
                [$variable, $pattern, $env] = ['QUERY_STRING', self::apacheUnescape($m[1], '"'), $m[2]];
            } elseif (preg_match('/^SetEnvIfNoCase (\S+) (\S+) (waf_[a-z]+)$/', $line, $m) === 1 && isset(self::VARIABLES[$m[1]])) {
                [$variable, $pattern, $env] = [self::VARIABLES[$m[1]], self::apacheUnescape($m[2], null), $m[3]];
            } else {
                throw new RuntimeException('8G line '.($number + 1).' has a shape the OLS ruleset does not know: '.$line);
            }

            // Compiled here, before anything is written: OLS's `-t` does not
            // check rewrite regexes, so an invalid one would only show up as
            // every rule on the site quietly not applying.
            if (@preg_match('#'.str_replace('#', '\\#', $pattern).'#i', '') === false) {
                throw new RuntimeException('8G line '.($number + 1).' is not a valid regex once unescaped: '.$pattern);
            }

            $category = self::CATEGORIES[$env] ?? throw new RuntimeException('8G line '.($number + 1)." sets an unknown category {$env}");
            // Always grouped. A RewriteCond pattern that *starts* with `<`,
            // `>`, `=`, `-` or `!` is an operator, not a regex: the cookie rule
            // `<|>|\'|...` became "lexicographically less than" and blocked
            // every request (found on the OLS test server, 2026-09-30); `=?`
            // and `-------` were an equality test and a file test. `(?:...)`
            // matches exactly the same and can never be read as one.
            $rules[$category][] = [$variable, '(?:'.$pattern.')'];
        }

        return $rules;
    }
}
