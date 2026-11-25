<?php

namespace App\Services\Server\WebServers;

use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Sleep;

/**
 * `openlitespeed -t`, made capable of failing.
 *
 * ## Why this is not just a command
 *
 * In test mode OpenLiteSpeed does not treat a fatal configuration error as failure
 * (`lshttpdmain.cpp`: `if (ret && !m_iConfTestMode)`) and redirects the error log to
 * `{tmp}/testconf`. The exit code is decided at the end by reading that file back:
 *
 *  - absent or empty → **0**
 *  - non-empty, no `[ERROR]` in it → **1**
 *  - contains `[ERROR]` → **2**
 *
 * Test mode is also the one mode that does not create that directory
 * (`mkdir(DEFAULT_TMP_DIR, 0755)` sits behind `if (!m_iConfTestMode)`). When `/tmp/lshttpd` is
 * missing, which is normal after a reboot or before lshttpd first starts, the test exits 0 on
 * any configuration. The panel therefore creates the directory itself before asking.
 *
 * ## Why it is a class rather than a method on the driver
 *
 * `OlsDriver` tests before publishing one site's vhost; `OlsSharedConfig` tests before keeping
 * a write to `httpd_config.conf` (shared by every site) and rolls the file back on failure. Both
 * need the same fixed check. It cannot live on either: `OlsDriver` already depends on
 * `OlsSharedConfig`, and a container cycle hangs instead of failing loudly.
 */
class OlsConfigCheck
{
    public function __construct(private ServerOps $serverOps) {}

    public function run(array $context = []): ServerOpsResult
    {
        $context = $context + ['feature' => 'application', 'op' => 'ols_config_test'];

        // Not fatal: failing to create it only leaves the test uninformative.
        $this->serverOps->run(
            ['mkdir', '-p', $this->tmpDirectory()],
            $context + ['op' => 'ols_config_test_prepare'],
            timeout: 15,
        );

        // Exit 1 is declared an answer so a warning stays off the admin error dashboard; ok is
        // decided below.
        $result = $this->serverOps->run($this->command(), $context, expectedExitCodes: [1]);

        // Killed, not answered: about six seconds after a graceful restart OpenLiteSpeed kills
        // every `openlitespeed` process, a running test included. That says nothing about the
        // config, so ask again.
        for ($retry = 0; $retry < 2 && $this->killed($result); $retry++) {
            Sleep::for(2)->seconds();
            $result = $this->serverOps->run($this->command(), $context, expectedExitCodes: [1]);
        }

        // Exit 1 means the log held only warnings (test-mode log level is WARN); refusing
        // would turn a warning into a site that cannot be created. Errors are exit 2 and still
        // fail.
        //
        // Checked against 1 explicitly, not "below 2": a code the panel cannot read is a
        // failure. sudo refusing is also 1 but arrives with non-empty stderr, hence the check.
        if ($result->exitCode() === 1 && trim($result->errorOutput()) === '') {
            return new ServerOpsResult(true, $result->reference, $result->result);
        }

        return $result;
    }

    /**
     * Ended by a signal: ServerOps returns no process result, the same shape as a failed
     * spawn, for which asking again is harmless. Not our own timeout or sudo.
     */
    private function killed(ServerOpsResult $result): bool
    {
        return ! $result->ok && $result->result === null && ! $result->timedOut && ! $result->denied && ! $result->busy;
    }

    /**
     * @return array<int, string>
     */
    private function command(): array
    {
        // `openlitespeed -t`, not `lswsctrl config_test`: lswsctrl has no such verb and exits
        // non-zero, which would read as a rejected config.
        return (array) config(
            'server.web_server_drivers.openlitespeed.test_command',
            ['/usr/local/lsws/bin/openlitespeed', '-t'],
        );
    }

    /**
     * Where OpenLiteSpeed writes the log that decides the config test.
     *
     * Config because it is compiled in (`DEFAULT_TMP_DIR`) and a package could build it elsewhere.
     */
    private function tmpDirectory(): string
    {
        return rtrim((string) config('server.web_server_drivers.openlitespeed.tmp_dir', '/tmp/lshttpd'), '/');
    }
}
