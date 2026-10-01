<?php

namespace App\Exceptions\Server\Application;

use App\Services\Server\ServerOpsResult;
use Exception;

/**
 * A provisioning step failed on the server. Carries the step that broke and
 * the server-ops log reference, so the user is told which part failed and can
 * quote the reference — without the raw stderr reaching the API.
 *
 * It also carries an optional `reason` code. The reference is enough for
 * someone with access to the server-ops log and useless to everyone else, and
 * there is one failure where the log has nothing to offer either: a process
 * killed by the kernel writes no stderr at all. `fromResult()` reads the exit
 * status and names that case, so "the build failed, here is a reference to an
 * empty log" becomes "the server ran out of memory during the build".
 */
class ProvisioningFailedException extends Exception
{
    /**
     * 128 + SIGKILL(9). The shell's encoding of "something killed this",
     * which on a server under memory pressure means the OOM killer chose it.
     * There is no other common way for these commands to be signalled.
     */
    private const EXIT_KILLED = 137;

    public function __construct(
        public readonly string $step,
        public readonly string $reference,
        public readonly ?string $reason = null,
    ) {
        parent::__construct("Provisioning failed at step [{$step}] (reference {$reference})");
    }

    /**
     * Build from a failed operation, classifying what the exit status says.
     *
     * Deliberately narrow: it names the one failure mode that is otherwise
     * undiagnosable and leaves everything else unclassified rather than
     * guessing. A wrong reason is worse than none — it sends the user to fix
     * something that was never broken.
     */
    public static function fromResult(string $step, ServerOpsResult $result): self
    {
        return new self($step, $result->reference, self::classify($step, $result));
    }

    /**
     * `null` when the failure speaks for itself — the command's own output is
     * already in the log under this reference, and the user is better served
     * by it than by a category invented here.
     */
    private static function classify(string $step, ServerOpsResult $result): ?string
    {
        // The user's deploy script ran a git command against the remote —
        // nearly always the `git pull` the default script carried until
        // 2026-10-01 — and the remote asked for a login. The panel's own fetch
        // has the account's credential; the script never does, so on a private
        // repository it can only fail. Measured on GitHub 2026-10-01:
        //   fatal: could not read Username for 'https://github.com': No such device or address
        // That is git's own wording, the same for every host.
        if ($step === 'script' && preg_match("/could not read (Username|Password) for 'https?:/", $result->output()."\n".$result->errorOutput()) === 1) {
            return 'script_git_auth';
        }

        // A native addon needed compiling and this server has no compiler.
        //
        // Checked before the exit status because npm exits 1, which says
        // nothing on its own. node-gyp's wording is the unambiguous part:
        // "not found: make" is emitted only by its own `which` lookup for the
        // build tool, so there is no honest way to read it as anything else.
        //
        // Worth naming rather than leaving to the log, because the log is the
        // problem. npm writes thousands of peer-dependency warnings around
        // this one line, which is how a missing build-essential presented as
        // a dependency-resolution failure for two attempts on a real server.
        if (self::mentionsMissingBuildTool($result)) {
            return 'no_build_tools';
        }

        // Composer refused to resolve against the PHP it was run under.
        //
        // Same reasoning as the build tools above and the same risk: composer
        // prints its whole dependency tree around the one line that matters,
        // and "Your requirements could not be resolved" on its own sends the
        // reader to look for a broken package when the answer is the site's
        // PHP version.
        if (self::mentionsPlatformRequirement($result)) {
            return 'composer_platform';
        }

        $exitCode = $result->result?->exitCode();

        if ($exitCode !== self::EXIT_KILLED) {
            return null;
        }

        // A killed process may still have written something before it died,
        // and if it did that is the better explanation. Only claim
        // out-of-memory for the silent case this exists to describe.
        //
        // npm's peer-dependency warnings do not count as saying something:
        // npm prints hundreds of them before it does any work, so an npm
        // install the OOM killer stopped was never silent and was never
        // named. On the Apache test box `npm install n8n` was killed at
        // 3.8 GB and the panel reported the step with no reason at all.
        if (self::explainedItself($result->output()) || self::explainedItself($result->errorOutput())) {
            return null;
        }

        return 'out_of_memory';
    }

    /**
     * Whether the output holds anything but blank lines and npm warnings.
     */
    private static function explainedItself(string $output): bool
    {
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '' && ! preg_match('/^npm warn(ing)?\b/i', $line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Did composer fail on a platform requirement — a PHP version or a PHP
     * extension the interpreter it ran under does not provide?
     *
     * Measured against composer 2.10 on 2026-09-17 rather than remembered.
     * Both failures exit 2 and say, in the body of a much longer report:
     *
     *   - Root composer.json requires php ^9.0 but your php version (8.4.23)
     *     does not satisfy that requirement.
     *   - Root composer.json requires PHP extension ext-foo * but it is
     *     missing from your system. Install or enable PHP's foo extension.
     *
     * Matched on the distinctive tail of each rather than on "requirements
     * could not be resolved", which composer also prints for an ordinary
     * version conflict between two packages — a different problem, with a
     * different fix, and this class's own rule is that a wrong reason sends
     * someone to fix something that was never broken.
     *
     * That distinction was measured too, not assumed: an ordinary conflict
     * (`monolog/monolog ^3` against `psr/log ^1`) reports "found psr/log[…]
     * but it conflicts with your root composer.json require" and contains
     * none of these needles, while a *dependency* needing a newer PHP ends in
     * the same "does not satisfy that requirement" as a root one. So the
     * phrase separates platform from package, which is exactly the cut.
     */
    private static function mentionsPlatformRequirement(ServerOpsResult $result): bool
    {
        $output = $result->errorOutput()."\n".$result->output();

        foreach ([
            'does not satisfy that requirement',
            'but it is missing from your system',
            // The runtime half of the same fault: composer's generated guard,
            // reached when the install went through under one PHP and the code
            // is then run under an older one.
            'Composer detected issues in your platform',
        ] as $needle) {
            if (str_contains($output, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Did node-gyp fail to find a build tool?
     *
     * Matched against both streams: npm puts its own summary on stderr and the
     * gyp transcript can land on either depending on how the install was run.
     *
     * `make` and `g++` by name rather than a looser "gyp ERR" match, because
     * gyp reports compile errors with the same prefix -- and a source file
     * that will not compile is a different problem with a different fix. This
     * is the class's own rule: a wrong reason sends someone to fix something
     * that was never broken.
     */
    private static function mentionsMissingBuildTool(ServerOpsResult $result): bool
    {
        $output = $result->errorOutput()."\n".$result->output();

        foreach (['not found: make', 'not found: g++', 'not found: cc', 'not found: gcc'] as $needle) {
            if (str_contains($output, $needle)) {
                return true;
            }
        }

        return false;
    }
}
