<?php

namespace App\Services\Server\Applications;

/**
 * Turns the raw text of a `.env` into something a screen can render.
 *
 * Two jobs, both deliberately here rather than in the frontend: parsing the
 * file into key/value pairs, and judging it. The frontend should not have to
 * know dotenv quoting rules, nor what Laravel considers dangerous — a check
 * added here is a check that appears in the UI with no frontend change, the
 * same trade the site-type field schema already makes.
 */
class EnvironmentInspector
{
    /**
     * Key names whose values are never sent to the client.
     *
     * Matched on the key, not the value: guessing at whether a string "looks
     * secret" gets both answers wrong, and the key is what the author already
     * told us.
     */
    private const SECRET_PATTERN = '/(PASSWORD|SECRET|TOKEN|_KEY|^KEY$|SALT|HASH|DSN|CREDENTIAL|PRIVATE|AUTH)/i';

    /**
     * Parse into ordered pairs. Comments and blank lines are dropped — this is
     * the structured view; the raw text is what the editor shows and what is
     * written back, so nothing is lost.
     *
     * @return array<int, array{key: string, value: string|null, secret: bool}>
     */
    public function variables(string $raw): array
    {
        $variables = [];

        foreach ($this->lines($raw) as $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/', $line, $m) !== 1) {
                continue;
            }

            $secret = preg_match(self::SECRET_PATTERN, $m[1]) === 1;

            $variables[] = [
                'key' => $m[1],
                // Null, not a masked string: a `••••` in a JSON response is
                // something you can still copy, so it protects nobody.
                'value' => $secret ? null : $this->unquote(trim($m[2])),
                'secret' => $secret,
            ];
        }

        return $variables;
    }

    /**
     * Everything worth telling the user about this file, framework-aware.
     *
     * Each entry carries the key, its current value, a severity and a
     * suggested value, so the UI can offer a one-click fix without knowing
     * which framework it is looking at.
     *
     * @return array<int, array{key: string|null, value: string|null, severity: string, code: string, title: string, detail: string, suggested: string|null}>
     */
    public function checks(string $raw, string $framework): array
    {
        // Read from the raw text, not from variables(), which blanks secret
        // values before they leave the server. APP_KEY matches the secret
        // pattern, so reading the parsed value here would see null and report
        // a perfectly good key as missing — on every Laravel site.
        $values = $this->rawValues($raw);

        return array_merge(
            $this->syntaxChecks($raw, $framework),
            $this->frameworkChecks($raw, $framework, $values),
        );
    }

    /**
     * The four mistakes that actually happen, and are invisible until
     * something breaks.
     *
     * @param  array<int, mixed>  ...$_
     * @return array<int, array<string, mixed>>
     */
    private function syntaxChecks(string $raw, string $framework): array
    {
        $checks = [];
        $seen = [];

        foreach ($this->lines($raw) as $number => $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (! str_contains($trimmed, '=')) {
                $checks[] = $this->check('syntax_no_equals', 'error', null, null, null, [
                    'line' => $number + 1,
                ]);

                continue;
            }

            if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/', $line, $m) !== 1) {
                $checks[] = $this->check('syntax_bad_key', 'error', null, null, null, ['line' => $number + 1]);

                continue;
            }

            // `export FOO=bar` is valid in a shell and valid for dotenv, but
            // systemd's EnvironmentFile rejects the line outright — so a Node
            // service simply refuses to start, with the cause in the journal
            // and nowhere near this screen.
            if (str_starts_with(ltrim($line), 'export ') && $this->isSystemdRead($framework)) {
                $checks[] = $this->check('syntax_export', 'warning', $m[1], null, null, ['line' => $number + 1]);
            }

            if (substr_count($m[2], '"') % 2 !== 0) {
                $checks[] = $this->check('syntax_unbalanced_quote', 'error', $m[1], null, null, ['line' => $number + 1]);
            }

            if (isset($seen[$m[1]])) {
                // dotenv silently keeps the last one, so the value someone is
                // staring at may not be the value in effect.
                $checks[] = $this->check('duplicate_key', 'warning', $m[1], null, null, ['line' => $number + 1]);
            }

            $seen[$m[1]] = true;
        }

        return $checks;
    }

    /**
     * @param  array<string, string|null>  $values
     * @return array<int, array<string, mixed>>
     */
    private function frameworkChecks(string $raw, string $framework, array $values): array
    {
        $checks = [];

        if (in_array($framework, [FrameworkDetector::LARAVEL, FrameworkDetector::STATAMIC], true)) {
            // Per the security guide: APP_DEBUG=false in production, verified
            // rather than assumed. A debug page prints the database password
            // to anyone who triggers an error.
            if (($values['APP_DEBUG'] ?? null) !== null && $this->truthy($values['APP_DEBUG'])) {
                $checks[] = $this->check('app_debug_on', 'warning', 'APP_DEBUG', $values['APP_DEBUG'], 'false');
            }

            if (($values['APP_ENV'] ?? null) !== null && in_array(strtolower((string) $values['APP_ENV']), ['local', 'development', 'dev'], true)) {
                $checks[] = $this->check('app_env_local', 'warning', 'APP_ENV', $values['APP_ENV'], 'production');
            }

            if (! array_key_exists('APP_KEY', $values) || trim((string) ($values['APP_KEY'] ?? '')) === '') {
                $checks[] = $this->check('app_key_missing', 'error', 'APP_KEY', null, null);
            }
        }

        if ($framework === FrameworkDetector::NEXTJS) {
            // NEXT_PUBLIC_* is inlined into the browser bundle at build time.
            // A secret with that prefix is not leaked by a mistake later — it
            // is already public, to every visitor, right now.
            foreach ($this->variables($raw) as $variable) {
                if (str_starts_with($variable['key'], 'NEXT_PUBLIC_') && $variable['secret']) {
                    $checks[] = $this->check('next_public_secret', 'error', $variable['key'], null, null);
                }
            }
        }

        return $checks;
    }

    /**
     * @param  array<string, mixed>  $replace
     * @return array<string, mixed>
     */
    private function check(string $code, string $severity, ?string $key, ?string $value, ?string $suggested, array $replace = []): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'key' => $key,
            'value' => $value,
            'suggested' => $suggested,
            'title' => __("environment.checks.{$code}.title", $replace + ['key' => (string) $key]),
            'detail' => __("environment.checks.{$code}.detail", $replace + ['key' => (string) $key]),
        ];
    }

    /** Node services read the file through systemd, which is stricter. */
    private function isSystemdRead(string $framework): bool
    {
        return in_array($framework, [
            FrameworkDetector::NODE,
            FrameworkDetector::NEXTJS,
            FrameworkDetector::NUXT,
        ], true);
    }

    private function truthy(?string $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['true', '1', 'on', 'yes'], true);
    }

    /**
     * Every key with its real value. Server-side only — this is what the
     * checks reason about, and it never reaches a response.
     *
     * @return array<string, string>
     */
    private function rawValues(string $raw): array
    {
        $values = [];

        foreach ($this->lines($raw) as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/', $line, $m) === 1) {
                $values[$m[1]] = $this->unquote(trim($m[2]));
            }
        }

        return $values;
    }

    /**
     * @return array<int, string>
     */
    private function lines(string $raw): array
    {
        return preg_split('/\r?\n/', $raw) ?: [];
    }

    private function unquote(string $value): string
    {
        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            return str_replace(['\\"', '\\\\'], ['"', '\\'], substr($value, 1, -1));
        }

        if (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
            return substr($value, 1, -1);
        }

        // An unquoted `#` starts a comment — the value stops there, and a
        // screen showing the rest would be showing something dotenv ignores.
        return trim(preg_replace('/\s+#.*$/', '', $value) ?? $value);
    }
}
