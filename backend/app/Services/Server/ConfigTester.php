<?php

namespace App\Services\Server;

use App\Contracts\PhpStack;
use App\Services\Server\Php\IniSyntaxCheck;
use App\Services\Server\WebServers\OlsConfigCheck;

/**
 * Validates a service's configuration without applying it.
 *
 * Which command proves a config valid depends on the service, so it is a lookup. Services
 * with no meaningful test are not testable, and the API says so.
 *
 * Testing never reloads: it is what you do *before* deciding to apply a change.
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

        // OpenLiteSpeed's raw test exits 0 on any config when /tmp/lshttpd is missing, and 1 on a
        // mere warning, so use the wrapper that means something (restarts are refused on failure).
        $result = $key === 'openlitespeed'
            ? $this->olsCheck->run($context)
            : $this->serverOps->run($command, $context);

        // A PHP unit's test exits 0 over an ini PHP could not parse.
        if ($this->stack->versionForService($key) !== null) {
            $result = IniSyntaxCheck::apply($result);
        }

        return [
            'ok' => $result->ok,
            // The output names the file and line that is wrong; it describes the user's own
            // configuration, so it is safe to return.
            'output' => trim($result->output().' '.($result->result?->errorOutput() ?? '')),
        ];
    }

    /**
     * @return array<int, string>|null
     */
    private function command(string $key): ?array
    {
        // php8.4-fpm, php8.3-fpm … each version validates itself. The stack resolves the version
        // because the unit pattern differs between FPM and LSPHP.
        if (($version = $this->stack->versionForService($key)) !== null) {
            return $this->stack->configTestCommand($version);
        }

        $command = config("server.config_tests.{$key}");

        return is_array($command) ? $command : null;
    }
}
