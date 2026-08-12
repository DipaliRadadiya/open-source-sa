<?php

use App\Services\Panel\PanelUpdateRunner;

/*
 * The update is started from an HTTP request, so it inherits the panel's own
 * php-fpm cgroup — and systemd kills a unit's whole cgroup when it restarts,
 * which the update itself does partway through. A transient unit is the only
 * thing that escapes that; setsid and nohup both deal in signals, and a cgroup
 * kill is not a signal a process gets to ignore.
 *
 * Asserted on the command string rather than by running it: launching it for
 * real would spawn systemd units on whatever machine runs the suite.
 */

it('starts the update in a transient unit so a service restart cannot take it down', function () {
    $command = PanelUpdateRunner::transientCommand('/tmp/run-7.sh', '/tmp/update-7.log', '7');

    expect($command)
        ->toContain('systemd-run')
        // --collect so a finished unit does not linger in systemd's list; the
        // name is per-update so two runs can never collide on it.
        ->toContain('--collect')
        ->toContain("--unit='panel-update-7'")
        ->toContain('/tmp/run-7.sh')
        ->toContain('/tmp/update-7.log');
});

it('keeps the script attached to nothing that can hang up on it', function () {
    // Output has to go to the log and stdin to /dev/null, or the child holds
    // the php-fpm request's pipes open and the response never finishes
    // flushing.
    $fallback = PanelUpdateRunner::fallbackCommand('/tmp/run-7.sh', '/tmp/update-7.log');

    expect($fallback)
        ->toContain('setsid')
        ->toContain('< /dev/null')
        ->toContain('2>&1')
        ->toEndWith('&');
});

it('quotes paths, so a state directory with a space cannot split the command', function () {
    $command = PanelUpdateRunner::transientCommand('/tmp/a b/run.sh', '/tmp/a b/log', '9');

    expect($command)->toContain("'/tmp/a b/run.sh'")
        ->and(PanelUpdateRunner::fallbackCommand('/tmp/a b/run.sh', '/tmp/a b/log'))
        ->toContain("'/tmp/a b/run.sh'");
});
