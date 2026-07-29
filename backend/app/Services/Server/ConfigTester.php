<?php

namespace App\Services\Server;

/**
 * Validates a service's configuration without applying it.
 *
 * Which command proves a config is valid depends entirely on the service, so
 * it's a lookup rather than anything clever. Services with no meaningful test
 * simply aren't testable, and the API says so instead of inventing one.
 *
 * Testing never reloads. Checking whether a change is safe is precisely the
 * thing you do *before* deciding to apply it.
 */
class ConfigTester
{
    public function __construct(private ServerOps $serverOps) {}

    public function testable(string $key): bool
    {
        return $this->command($key) !== null;
    }

    /**
     * @return array{ok: bool, output: string}|null null when not testable
     */
    public function test(string $key): ?array
    {
        $command = $this->command($key);

        if ($command === null) {
            return null;
        }

        $result = $this->serverOps->run(
            $command,
            ['feature' => 'service', 'op' => 'config_test', 'service' => $key],
        );

        return [
            'ok' => $result->ok,
            // A config test's output is the whole point — it names the file and
            // line that is wrong. It describes the user's own configuration,
            // not our internals, so it is safe and useful to return.
            'output' => trim($result->output().' '.($result->result?->errorOutput() ?? '')),
        ];
    }

    /**
     * @return array<int, string>|null
     */
    private function command(string $key): ?array
    {
        // php8.4-fpm, php8.3-fpm … each version validates itself.
        if (preg_match('/^php(\d+\.\d+)-fpm$/', $key, $matches) === 1) {
            return [(string) config('server.php_fpm_binary', '/usr/sbin/php-fpm').$matches[1], '-t'];
        }

        $command = config("server.config_tests.{$key}");

        return is_array($command) ? $command : null;
    }
}
