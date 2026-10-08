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

        // A registry refused to serve the image.
        //
        // Named here for the same reason as the two above: the failure is
        // actionable and the log does not read as an action. `docker compose up`
        // reports it as one line among the pull progress — "Error pull access
        // denied" — inside output whose next line is a bare `denied`, and a
        // reader who does not already know Docker's wording sees a broken step
        // rather than a credential they need to supply.
        // Checked BEFORE the no-credential case, and the order is load-bearing.
        // Docker Hub answers a rejected credential with "authentication
        // required - incorrect username or password", and a self-hosted registry
        // v2 with a 401 — neither of which contains the other's needles, but a
        // future needle added loosely to either set could overlap. The more
        // specific diagnosis goes first.
        // FS-B3: the download never happened — a mirror or release host that
        // did not resolve or answer. Saved with no reason it read as a broken
        // installer; it is the network, and retrying later is the fix.
        if (preg_match('/(Could not resolve host|Temporary failure in name resolution|unable to resolve host address|Failed to connect to|Connection timed out|Connection refused|Operation timed out|Network is unreachable)/i', $result->output()."\n".$result->errorOutput()) === 1) {
            return 'download_unreachable';
        }

        if (self::mentionsRejectedCredentials($result)) {
            return 'registry_credentials_rejected';
        }

        if (self::mentionsRegistryAuth($result)) {
            return 'registry_auth';
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
     * Did the pull fail because the registry would not authorise it?
     *
     * Measured against Docker 29.8.1 and Compose 5.5.1 on 2026-09-29 rather
     * than remembered, because each registry words this differently and only
     * one of the four phrasings is the one people quote:
     *
     *   - Docker Hub: `pull access denied for <repo>, repository does not
     *     exist or may require 'docker login'`
     *   - GHCR:       `error from registry: denied`
     *   - ECR:        `pull access denied, repository does not exist or may
     *     require authorization: authorization failed: no basic auth
     *     credentials`
     *   - registry v2 (GitLab, Harbor, self-hosted): `unauthorized:
     *     authentication required` — this one is from the registry v2 spec and
     *     is the ONE needle here that was not observed on a real box. Kept
     *     because it costs nothing and the spec mandates the wording; named as
     *     unverified rather than quietly listed beside four measurements.
     *
     * `from registry: denied` rather than a bare `denied`, which appears in
     * unrelated daemon errors and on its own line in the very output above.
     *
     * **The reason deliberately does not claim the image is private.** Docker
     * Hub answers "does not exist" and "exists but is yours" with the same
     * sentence — it says so in the sentence — so a typo in a public image name
     * lands here too. The wording this maps to names both, which is the honest
     * answer and still the useful one: either way the next step is to check the
     * name, and registry credentials are not something the panel can store yet.
     *
     * Two failures that reach the same step are deliberately left
     * unclassified, both verified on the same box:
     *
     *   - a tag that does not exist on a repository that does, which reports
     *     `not found` with no mention of access;
     *   - a registry that cannot be reached, which reports `dial tcp: lookup
     *     … no such host`.
     *
     * Neither is fixed by a credential, and claiming otherwise is this class's
     * own stated failure mode.
     */
    private static function mentionsRegistryAuth(ServerOpsResult $result): bool
    {
        $output = $result->errorOutput()."\n".$result->output();

        foreach ([
            'pull access denied',
            'from registry: denied',
            'unauthorized: authentication required',
            'no basic auth credentials',
        ] as $needle) {
            if (str_contains($output, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Did the registry reject the credential the panel sent?
     *
     * A different failure from {@see self::mentionsRegistryAuth()} with a
     * different fix, which is the only reason it is worth a reason code of its
     * own: "you have not configured a credential for this registry" sends
     * somebody to the Docker page, and "the credential you configured was
     * refused" sends them to rotate a token. Reporting the first for the second
     * is how someone ends up re-entering a registry they already added.
     *
     * Both needles measured on 2026-09-29 against a real private repository:
     *
     *   - Docker Hub: `authentication required - incorrect username or
     *     password`
     *   - self-hosted registry v2: `unexpected status from HEAD request to
     *     http://…/v2/…/manifests/1: 401 Unauthorized`
     *
     * `401 Unauthorized` is narrow enough here despite looking generic: this
     * output is a container pull, and a 401 in it is the registry declining the
     * credentials in play. The alternative — matching the whole HEAD sentence —
     * would break on the next Docker release that rewords its own transport
     * errors, which is not the part worth pinning.
     *
     * The token itself is never in this text. Docker echoes the USERNAME back in
     * the Hub variant and nothing else, which is why the raw output still must
     * not reach the API — only this reason code does.
     */
    private static function mentionsRejectedCredentials(ServerOpsResult $result): bool
    {
        $output = $result->errorOutput()."\n".$result->output();

        foreach ([
            'incorrect username or password',
            '401 Unauthorized',
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
