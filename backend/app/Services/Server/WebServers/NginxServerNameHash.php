<?php

namespace App\Services\Server\WebServers;

use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOpsResult;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/** Reconcile only nginx's diagnosed hostname hash-capacity failure. */
class NginxServerNameHash
{
    // Hostname accepts 253 bytes. nginx adds a pointer/length and alignment;
    // 256 cannot hold that element, while the next power of two (512) can.
    private const BUCKET_SIZE = 512;

    private const DISCOVERY_SECONDS = 30;

    public function __construct(private ManagedFile $files) {}

    /** @param callable(): ServerOpsResult $test */
    public function test(callable $test): ServerOpsResult
    {
        $result = $test();

        if (! $this->needsRepair($result)) {
            return $result;
        }

        // ServerOps defaults to 60 seconds per attempt. Discovery (including
        // the under-lock re-test and final read) has a hard budget; the lease
        // also reserves three complete retry-adjusted operations for the final
        // write/test/rollback. It must not expire while a failed write restores.
        $attempts = max(1, (int) config('server.transient.attempts', 1));
        $delay = max(0, (int) config('server.transient.delay_ms', 1500)) / 1000;
        $operationSeconds = 60 * $attempts + $delay * ($attempts - 1);
        $lease = (int) ceil(self::DISCOVERY_SECONDS + 3 * $operationSeconds + 30);

        try {
            return Cache::lock('nginx-server-name-hash', $lease)->block(20, function () use ($test): ServerOpsResult {
                $deadline = now()->getTimestamp() + self::DISCOVERY_SECONDS;
                // Another panel writer may have repaired it while we waited.
                $result = $test();

                return $this->needsRepair($result) ? $this->repair($test, $result, $deadline) : $result;
            });
        } catch (LockTimeoutException) {
            return $result;
        }
    }

    private function needsRepair(ServerOpsResult $result): bool
    {
        return $result->failed()
            && preg_match('~^(?:nginx: \[emerg\] |\d{4}/\d{2}/\d{2} \d{2}:\d{2}:\d{2} \[emerg\] \d+\#\d+: )?could not build server_names_hash, you should increase server_names_hash_bucket_size: (\d+)[ \t]*\r?$~m', $result->errorOutput(), $matches) === 1
            && (int) $matches[1] < self::BUCKET_SIZE;
    }

    /** @param callable(): ServerOpsResult $test */
    private function repair(callable $test, ServerOpsResult $failure, int $deadline): ServerOpsResult
    {
        $main = (string) config('server.web_server_drivers.nginx.config_path', '/etc/nginx/nginx.conf');
        $originals = [];
        $directives = [];
        $httpOpenings = [];
        $active = [];

        if (! $this->scan($main, false, dirname($main), $originals, $directives, $httpOpenings, $active, $deadline)
            || count($httpOpenings) !== 1 || count($directives) > 1 || now()->getTimestamp() >= $deadline) {
            // An unreadable/ambiguous custom layout is not permission to write
            // a new directive that could duplicate one we could not discover.
            return $failure;
        }

        if ($directives !== []) {
            [$path, $offset, $length, $value] = $directives[0];

            if ($value >= self::BUCKET_SIZE) {
                return $failure;
            }

            $contents = substr_replace($originals[$path], (string) self::BUCKET_SIZE, $offset, $length);
        } else {
            $path = $main;
            $contents = substr_replace($originals[$path], "\n    server_names_hash_bucket_size ".self::BUCKET_SIZE.';', $httpOpenings[0], 0);
        }

        $context = ['feature' => 'web_server', 'op' => 'nginx_server_name_hash'];
        $current = $this->files->get($path, $context);

        if ($current->failed() || $current->output() !== $originals[$path] || now()->getTimestamp() >= $deadline) {
            return $current->failed() ? $current : $failure;
        }

        $written = $this->files->put($path, $contents, $context);
        $checked = $written->ok ? $test() : $written;

        if ($checked->failed()) {
            // tee can fail after truncating. Restore on a write failure too.
            $restored = $this->files->put($path, $originals[$path], $context + ['rollback' => true]);

            return $restored->failed() ? $restored : $checked;
        }

        // No reload here. Provision/apply/resync keep their existing gate.
        return $checked;
    }

    /**
     * Token offsets allow replacing only the number, not rendering nginx's
     * config. Comments and quoted strings (including braces) remain untouched.
     * Includes inherit http context; relative paths use nginx's conf prefix,
     * the main config directory, NOT the included file's directory.
     *
     * @param  array<string, string>  $originals
     * @param  list<array{string, int, int, int}>  $directives
     * @param  list<int>  $httpOpenings
     * @param  array<string, bool>  $active
     */
    private function scan(string $path, bool $http, string $prefix, array &$originals, array &$directives, array &$httpOpenings, array &$active, int $deadline): bool
    {
        if (isset($active[$path]) || count($active) >= 16 || count($originals) >= 1024 || now()->getTimestamp() >= $deadline) {
            return false;
        }

        if (! isset($originals[$path])) {
            $read = $this->files->get($path, ['feature' => 'web_server', 'op' => 'nginx_server_name_hash']);

            if ($read->failed()) {
                return false;
            }

            $originals[$path] = $read->output();
        }

        $active[$path] = true;
        preg_match_all('~\#[^\r\n]*|"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|(?:\$\{[^}]*\}|\\\\.|[^\s{};#"\'\\\\])+|[{};]~', $originals[$path], $matches, PREG_OFFSET_CAPTURE);
        $parents = $http ? ['http'] : [];
        $statement = [];

        foreach ($matches[0] as [$token, $offset]) {
            if (now()->getTimestamp() >= $deadline) {
                return false;
            }

            if (str_starts_with($token, '#')) {
                continue;
            }

            if ($token === '{') {
                $name = $statement[0][0] ?? '';

                if (! $http && $name === 'http' && $parents === []) {
                    $httpOpenings[] = $offset + 1;
                }

                $parents[] = $name;
                $statement = [];
            } elseif ($token === '}') {
                if ($parents === [] || $statement !== []) {
                    return false;
                }

                array_pop($parents);
            } elseif ($token === ';') {
                if ($parents === ['http'] && ($statement[0][0] ?? '') === 'server_names_hash_bucket_size') {
                    if (count($statement) !== 2) {
                        return false;
                    }

                    [$number, $numberOffset] = $statement[1];

                    if (in_array($number[0] ?? '', ['\'', '"'], true) && substr($number, -1) === $number[0]) {
                        $number = substr($number, 1, -1);
                        $numberOffset++;
                    }

                    if (! ctype_digit($number)) {
                        return false;
                    }

                    $directives[] = [$path, $numberOffset, strlen($number), (int) $number];
                } elseif ($parents === ['http'] && ($statement[0][0] ?? '') === 'include') {
                    if (count($statement) !== 2) {
                        return false;
                    }

                    $pattern = trim($statement[1][0], '\'"');

                    // Variables/escaped or wildcard directories need a fuller
                    // parser/root-side directory probe. Refuse, never guess.
                    if (str_contains($pattern, '$') || str_contains($pattern, '\\')) {
                        return false;
                    }

                    $pattern = str_starts_with($pattern, '/') ? $pattern : $prefix.'/'.$pattern;
                    $wildcard = strpbrk($pattern, '*?[') !== false;

                    if ($wildcard && (strpbrk(dirname($pattern), '*?[') !== false || ! is_readable(dirname($pattern)))) {
                        return false;
                    }

                    $paths = $wildcard ? glob($pattern) : [$pattern];

                    if ($paths === false) {
                        return false;
                    }

                    foreach ($paths as $included) {
                        if (! $this->scan($included, true, $prefix, $originals, $directives, $httpOpenings, $active, $deadline)) {
                            return false;
                        }
                    }
                }

                $statement = [];
            } else {
                $statement[] = [$token, $offset];
            }
        }

        unset($active[$path]);

        return $parents === ($http ? ['http'] : []) && $statement === [];
    }
}
