<?php

namespace App\Services\Server;

use App\Contracts\PhpStack;
use App\Services\Server\Php\IniSyntaxCheck;
use App\Services\Server\WebServers\OlsConfigCheck;

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
    public function __construct(
        private ServerOps $serverOps,
        private PhpStack $stack,
        private OlsConfigCheck $olsCheck,
    ) {}

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

        $context = ['feature' => 'service', 'op' => 'config_test', 'service' => $key];

        // OpenLiteSpeed's raw test exits 0 on any config when /tmp/lshttpd is
        // missing, and 1 on a mere warning. A restart is now refused on a
        // failed test, so this one has to be the version that means something.
        $result = $key === 'openlitespeed'
            ? $this->olsCheck->run($context)
            : $this->serverOps->run($command, $context);

        // A PHP unit's test exits 0 over an ini PHP could not parse.
        if ($this->stack->versionForService($key) !== null) {
            $result = IniSyntaxCheck::apply($result);
        }

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
        // Which PHP version this unit is, according to the stack that named
        // it — the unit pattern differs between FPM and LSPHP.
        if (($version = $this->stack->versionForService($key)) !== null) {
            return $this->stack->configTestCommand($version);
        }

        $command = config("server.config_tests.{$key}");

        return is_array($command) ? $command : null;
    }
}
