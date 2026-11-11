<?php

namespace App\Services\Server\WebServers;

use App\Services\Panel\OlsWebAdmin;
use App\Services\Server\ServerOps;
use Illuminate\Support\Sleep;

/**
 * Stop OpenLiteSpeed, wait until every server process is gone, start it.
 *
 * For the server-level settings a graceful restart does not pick up: the
 * trusted addresses behind "Use Client IP in Header" were still the old ones
 * after `lswsctrl restart`, and right after a cold start (measured on a real
 * server, 2026-11-11). The same sequence as OlsWebAdmin::restartCold(), which
 * runs as root; this one goes through ServerOps so `sites:resync` can run it as
 * the panel's own account (systemctl, ps and kill are all in its grant). Costs
 * the sites a few seconds.
 */
class OlsColdRestart
{
    public function __construct(private ServerOps $serverOps) {}

    /** True when OpenLiteSpeed was stopped and started again. */
    public function run(int $grace = 15): bool
    {
        $context = ['feature' => 'web_server', 'op' => 'ols_cold_restart'];

        if ($this->serverOps->run(['systemctl', 'stop', OlsWebAdmin::UNIT], $context)->failed()) {
            return false;
        }

        if (! $this->waitForExit($grace, $context)) {
            $this->signal('-TERM', $context);

            if (! $this->waitForExit($grace, $context)) {
                $this->signal('-KILL', $context);

                if (! $this->waitForExit(5, $context)) {
                    // Started beside a process still holding the ports, it
                    // could not bind them; left stopped is the honest state.
                    return false;
                }
            }
        }

        $this->serverOps->run(['systemctl', 'reset-failed', OlsWebAdmin::UNIT], $context);

        return $this->serverOps->run(['systemctl', 'start', OlsWebAdmin::UNIT], $context)->ok;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<int>
     */
    private function serverPids(array $context): array
    {
        $result = $this->serverOps->run(['ps', '-C', OlsWebAdmin::PROCESS, '-o', 'pid='], $context, expectedExitCodes: [1]);

        return array_values(array_filter(
            array_map('intval', preg_split('/\s+/', trim($result->output())) ?: []),
            fn (int $pid) => $pid > 1,
        ));
    }

    /** @param  array<string, mixed>  $context */
    private function signal(string $signal, array $context): void
    {
        $pids = $this->serverPids($context);

        if ($pids !== []) {
            $this->serverOps->run(['kill', $signal, ...array_map('strval', $pids)], $context);
        }
    }

    /** @param  array<string, mixed>  $context */
    private function waitForExit(int $seconds, array $context): bool
    {
        for ($waited = 0; ; $waited++) {
            if ($this->serverPids($context) === []) {
                return true;
            }

            if ($waited >= $seconds) {
                return false;
            }

            Sleep::for(1)->second();
        }
    }
}
