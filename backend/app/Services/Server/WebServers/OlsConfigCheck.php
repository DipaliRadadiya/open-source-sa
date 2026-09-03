<?php

namespace App\Services\Server\WebServers;

use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;

/**
 * `openlitespeed -t`, made capable of failing.
 *
 * ## Why this is not just a command
 *
 * OpenLiteSpeed does not report a broken configuration through the return path
 * of its config test. In test mode a fatal configuration error is explicitly
 * *not* treated as failure — `lshttpdmain.cpp` reads
 * `if (ret && !m_iConfTestMode)` — and the error log is redirected to
 * `{tmp}/testconf` instead. The exit code is then decided at the very end by
 * reading that file back:
 *
 *  - absent or empty → **0**
 *  - non-empty, no `[ERROR]` in it → **1**
 *  - contains `[ERROR]` → **2**
 *
 * ⚠️ **And test mode is the one mode that does not create that directory.**
 * `mkdir(DEFAULT_TMP_DIR, 0755)` sits behind `if (!m_iConfTestMode)`. So when
 * `/tmp/lshttpd` is missing the log cannot be written, the file is never
 * found, and the test exits 0 on any configuration however broken. That is not
 * an edge case: `/tmp` is tmpfs or is cleaned by systemd-tmpfiles, so it is the
 * normal state of a rebooted box and the permanent state of one where lshttpd
 * has never started — which is exactly when a panel provisions its first site.
 *
 * The panel therefore creates the directory itself before asking.
 *
 * ## Why it is a class rather than a method on the driver
 *
 * There are two callers and they are not the same danger. `OlsDriver` tests
 * before publishing one site's vhost; `OlsSharedConfig` tests before keeping a
 * write to `httpd_config.conf`, which is the file every site on the box shares
 * — and rolls the whole file back when the test fails. That rollback is the
 * single most important use of the test in the panel, and it had its own
 * duplicate copy of the command, so a fix applied to the driver alone would
 * have left it exiting 0 on anything: a rollback guarding every site, wired to
 * a check that could not fail.
 *
 * It cannot live on either of them, either. `OlsDriver` already depends on
 * `OlsSharedConfig`, so the dependency can only point one way, and a container
 * cycle here does not fail loudly — it hangs.
 */
class OlsConfigCheck
{
    public function __construct(private ServerOps $serverOps) {}

    public function run(array $context = []): ServerOpsResult
    {
        $context = $context + ['feature' => 'application', 'op' => 'ols_config_test'];

        // Not fatal. Failing to create it leaves the test exactly as
        // uninformative as it has always been, which is no reason to refuse
        // the write it was asked about.
        $this->serverOps->run(
            ['mkdir', '-p', $this->tmpDirectory()],
            $context + ['op' => 'ols_config_test_prepare'],
            timeout: 15,
        );

        // Declared as an answer rather than a failure so a warning does not
        // land on the admin error dashboard. It does not make the result ok —
        // that decision is below, where it can be explained.
        $result = $this->serverOps->run($this->command(), $context, expectedExitCodes: [1]);

        // Exit 1 is "the log had something in it, none of it an error" — the
        // log level in test mode is WARN, so this is a deprecation notice and
        // friends. Refusing here would turn a warning into a site that cannot
        // be created, and until the directory above was created the test
        // exited 0 regardless, so exit 1 has never been reachable on a real
        // server: treating it as failure would be a brand-new refusal shipped
        // as a fix. Errors are exit 2 and still fail.
        //
        // Deliberately checked against 1 and not "anything below 2": a code
        // the panel has no reading for is a failure, including the ones that
        // never came from OpenLiteSpeed at all — sudo refusing is 1 too, but
        // it arrives with a non-empty stderr, which is why `expectedExitCodes`
        // above leaves `answered` false for it while this returns ok. That is
        // the wrong shape to rely on, so it is checked explicitly.
        if ($result->exitCode() === 1 && trim($result->errorOutput()) === '') {
            return new ServerOpsResult(true, $result->reference, $result->result);
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    private function command(): array
    {
        // `openlitespeed -t`, not `lswsctrl config_test`: lswsctrl has no such
        // verb. It prints its usage and exits non-zero for anything outside
        // start|stop|restart|reload|status, so an earlier default failed
        // exactly the way a rejected config fails — every write through
        // OlsSharedConfig would have rolled itself back.
        return (array) config(
            'server.web_server_drivers.openlitespeed.test_command',
            ['/usr/local/lsws/bin/openlitespeed', '-t'],
        );
    }

    /**
     * Where OpenLiteSpeed writes the log that decides the config test.
     *
     * Config because it is compiled in (`DEFAULT_TMP_DIR`) and a package could
     * build it elsewhere — the same reason every other path on this driver is
     * config: a wrong one should be an edit, not a patch.
     */
    private function tmpDirectory(): string
    {
        return rtrim((string) config('server.web_server_drivers.openlitespeed.tmp_dir', '/tmp/lshttpd'), '/');
    }
}
