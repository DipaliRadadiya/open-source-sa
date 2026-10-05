<?php

use Illuminate\Support\Facades\Process;

/**
 * install.sh writes its config files and systemd units from **unquoted**
 * heredocs, and has to: the bodies are full of `${APP_DIR}` and `${PANEL_SLUG}`,
 * which a quoted delimiter would write through literally.
 *
 * The price is that those bodies are also a place where the shell performs
 * command substitution — and they are heavily commented prose. That combination
 * shipped twice. A comment reading
 *
 *     # Reads `high` before `default`: still one job at a time, ...
 *
 * made the shell run `high` and `default`. Both exited 127, the ERR trap fired
 * inside the subshell and printed the installer's whole "Install stopped"
 * report, and the parent carried on — so every install on every stack ended
 * with two fatal errors that were not real, and the unit written to disk had
 * the trap's own output substituted into the comment.
 *
 * The scan lives in `tests/install-heredoc-guard.py` (it needs a real heredoc
 * state machine, not a grep). This test is how it gets run: the suite is the
 * thing that actually executes on every change.
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
     * The guard is the only thing standing between a backtick and a fake fatal
     * error on every install, so "the guard reported clean" has to mean it
     * looked. Its own self-test plants defects — in unquoted bodies, in quoted
     * ones, in herestrings — and checks each verdict. Without this, a scanner
     * that silently lost sync with the file would read as a pass.
     */
    it('runs its own self-test, so a clean report means it looked', function () {
        $result = Process::run(['python3', heredocGuard(), '--self-test']);

        expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput());
    });

    /**
     * The two bodies that actually shipped the bug. Asserted as text as well as
     * through the guard: if someone narrows the guard, these still fail.
     */
    it('keeps the prose in the two bodies that shipped the bug in single quotes', function () {
        $source = installerSource();

        expect($source)->toContain("# Reads 'high' before 'default':")
            ->and($source)->toContain("# 'context /api' and 'context /sanctum' alongside")
            ->and($source)->not->toContain('# Reads `high`')
            ->and($source)->not->toContain('# `context /api`');
    });
});
