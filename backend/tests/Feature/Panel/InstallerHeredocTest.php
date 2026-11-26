<?php

use Illuminate\Support\Facades\Process;

/**
 * install.sh writes its config files and systemd units from **unquoted**
 * heredocs, and has to: the bodies are full of `${APP_DIR}` and `${PANEL_SLUG}`,
 * which a quoted delimiter would write through literally.
 *
 * The price is that the shell also performs command substitution in those bodies, which are
 * heavily commented prose. A comment reading
 *
 *     # Reads `high` before `default`: still one job at a time, ...
 *
 * made the shell run `high` and `default`: both exited 127, the ERR trap printed the installer's
 * whole "Install stopped" report from the subshell, and the unit written to disk had the trap's
 * output substituted into the comment.
 *
 * The scan lives in `tests/install-heredoc-guard.py` (it needs a real heredoc state machine,
 * not a grep); this test runs it on every suite run.
 */
function heredocGuard(): string
{
    $path = base_path('../tests/install-heredoc-guard.py');

    if (! is_file($path)) {
        test()->markTestSkipped('tests/install-heredoc-guard.py is not in this checkout');
    }

    if (! shell_exec('command -v python3')) {
        test()->markTestSkipped('python3 is not available');
    }

    return $path;
}

describe('no command substitution in install.sh heredocs', function () {
    it('passes the guard', function () {
        installerSource();

        $result = Process::run(['python3', heredocGuard(), base_path('../install.sh')]);

        expect($result->exitCode())->toBe(
            0,
            "install.sh has command substitution in an unquoted heredoc body:\n"
            .$result->output().$result->errorOutput()
        );
    });

    /**
     * "The guard reported clean" has to mean it looked. Its self-test plants defects in unquoted
     * bodies, quoted ones and herestrings and checks each verdict; otherwise a scanner that lost
     * sync with the file would read as a pass.
     */
    it('runs its own self-test, so a clean report means it looked', function () {
        $result = Process::run(['python3', heredocGuard(), '--self-test']);

        expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput());
    });

    /**
     * The two bodies that shipped the bug, asserted as text too: narrowing the guard must not
     * hide them.
     */
    it('keeps the prose in the two bodies that shipped the bug in single quotes', function () {
        $source = installerSource();

        expect($source)->toContain("# Reads 'high' before 'default':")
            ->and($source)->toContain("# 'context /api' and 'context /sanctum' alongside")
            ->and($source)->not->toContain('# Reads `high`')
            ->and($source)->not->toContain('# `context /api`');
    });
});
