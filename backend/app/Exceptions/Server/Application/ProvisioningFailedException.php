<?php

namespace App\Exceptions\Server\Application;

use App\Services\Server\ServerOpsResult;
use Exception;

/**
 * A provisioning step failed on the server. Carries the step that broke and
 * the server-ops log reference, so the user is told which part failed and can
 * quote the reference — without the raw stderr reaching the API.
 *
 * It also carries an optional `reason` code. A process killed by the kernel writes no stderr, so
 * `fromResult()` reads the exit status and names that case ("the server ran out of memory during
 * the build") instead of pointing at an empty log.
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
     * Deliberately narrow: it names failure modes that are otherwise undiagnosable and leaves the
     * rest unclassified. A wrong reason is worse than none: it sends the user to fix something
     * that was never broken.
     *
     * @param  array<int, string>  $command  what was run; an exit status means nothing without
     *                                       its tool (22 is a curl HTTP error)
     */
    public static function fromResult(string $step, ServerOpsResult $result, array $command = []): self
    {
        return new self($step, $result->reference, self::classify($step, $result, $command));
    }

    /**
     * `null` when the failure speaks for itself — the command's own output is
     * already in the log under this reference, and the user is better served
     * by it than by a category invented here.
     *
     * @param  array<int, string>  $command
     */
    private static function classify(string $step, ServerOpsResult $result, array $command = []): ?string
    {
        // The user's deploy script ran a git command against a private remote and it asked for a
        // login. The panel's own fetch has the account's credential; the script never does.
        // git's wording is the same for every host:
        //   fatal: could not read Username for 'https://github.com': No such device or address
        if ($step === 'script' && preg_match("/could not read (Username|Password) for 'https?:/", $result->output()."\n".$result->errorOutput()) === 1) {
            return 'script_git_auth';
        }

        // The panel's own fetch: the repository or the branch is not there, or the account cannot
        // read it. git's wording:
        //   fatal: couldn't find remote ref <branch>
        //   fatal: could not read Username for 'https://github.com': … (no
        //     account, and the repository is missing or private)
        //   fatal: Authentication failed for '<url>' (a token GitHub refused)
        // An unreachable host falls through to `download_unreachable` below.
        if ($step === 'fetch') {
            $output = $result->output()."\n".$result->errorOutput();

            if (str_contains($output, "couldn't find remote ref")) {
                return 'git_branch_missing';
            }

            if (preg_match("/could not read (Username|Password) for 'https?:|Authentication failed for|Repository not found|repository '[^']*' not found/i", $output) === 1) {
                return 'git_repository_unreachable';
            }
        }

        // A native addon needed compiling and this server has no compiler.
        // Checked before the exit status because npm exits 1, which says nothing on its own;
        // node-gyp's "not found: make" is unambiguous. Worth naming because npm buries that line
        // under thousands of peer-dependency warnings.
        if (self::mentionsMissingBuildTool($result)) {
            return 'no_build_tools';
        }

        // Composer refused to resolve against the PHP it was run under. Composer prints its whole
        // dependency tree around the one line that matters, which sends the reader looking for a
        // broken package when the answer is the site's PHP version.
        if (self::mentionsPlatformRequirement($result)) {
            return 'composer_platform';
        }

        // The container could not bind its published host port because something on the server
        // already holds it. Docker's wording:
        //   failed to bind host port 127.0.0.1:80/tcp: address already in use
        //   Bind for 127.0.0.1:20001 failed: port is already allocated
        // The form refuses a port that is listened on; this names the race and anything that got
        // there another way. Only for the step that publishes ports ("address already in use" from
        // a deploy script is somebody else's process).
        if ($step === 'container_start' && preg_match('/(bind host port \S+: address already in use|Bind for \S+ failed: port is already allocated)/i', $result->output()."\n".$result->errorOutput()) === 1) {
            return 'container_port_in_use';
        }

        // A download or unpack the installer ran itself. Named from curl's documented exit status
        // (`man curl`, EXIT CODES), not its wording, which varies by version and locale; a download
        // killed at its timeout has no wording at all (no process result, no stderr, only
        // `timedOut`).
        $tool = self::toolOf($command);

        if ($tool === 'curl') {
            $reason = self::curlFailure($result);

            if ($reason !== null) {
                return $reason;
            }
        }

        if ($step === 'extract' && in_array($tool, ['tar', 'unzip'], true) && self::mentionsBrokenArchive($result)) {
            return 'archive_invalid';
        }

        // The download never happened: a mirror or release host that did not resolve or answer.
        // It is the network, and retrying later is the fix.
        if (preg_match('/(Could not resolve host|Temporary failure in name resolution|unable to resolve host address|Failed to connect to|Connection timed out|Connection refused|Operation timed out|Network is unreachable)/i', $result->output()."\n".$result->errorOutput()) === 1) {
            return 'download_unreachable';
        }

        // A registry refused to serve the image. `docker compose up` reports it as one line among
        // the pull progress, which reads as a broken step rather than a credential to supply.
        // Rejected credentials are checked BEFORE the no-credential case, and the order is
        // load-bearing: the more specific diagnosis goes first.
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

        // A killed process may still have written something before it died, and that is the better
        // explanation: only claim out-of-memory for the silent case. npm's peer-dependency
        // warnings do not count as saying something (npm prints hundreds before doing any work).
        if (self::explainedItself($result->output()) || self::explainedItself($result->errorOutput())) {
            return null;
        }

        return 'out_of_memory';
    }

    /**
     * The program a command runs, looking past the `runuser -u <name> --`
     * the installers wrap the application's own tooling in.
     *
     * @param  array<int, string>  $command
     */
    private static function toolOf(array $command): ?string
    {
        if (($command[0] ?? null) === 'runuser') {
            $separator = array_search('--', $command, true);

            return $separator === false ? null : ($command[$separator + 1] ?? null);
        }

        return $command[0] ?? null;
    }

    /**
     * What a failed curl download was, by its exit status.
     *
     * 5/6/7 the proxy or host did not resolve or accept the connection, 28 timed out, 22 the host
     * answered with an HTTP error (the installers pass `--fail`), 18/52/55/56 the connection died
     * part-way, and the TLS family (35, 51, 58, 59, 60, 77, 83, 90, 91) a secure connection that
     * could not be trusted or set up. Anything else stays unnamed.
     */
    private static function curlFailure(ServerOpsResult $result): ?string
    {
        // Killed at the ceiling: there is no process result to read. The ceiling and curl's own
        // `--max-time` are the same number and the ceiling starts first, so this is how a stalled
        // download usually ends.
        if ($result->timedOut) {
            return 'download_timeout';
        }

        return match ($result->exitCode()) {
            5, 6, 7 => 'download_unreachable',
            28 => 'download_timeout',
            22 => 'download_http_error',
            18, 52, 55, 56 => 'download_interrupted',
            35, 51, 58, 59, 60, 77, 83, 90, 91 => 'download_tls',
            default => null,
        };
    }

    /**
     * Did tar or unzip refuse the file as not being the archive it should be?
     *
     * Matched on the tools' wording, not the exit status: tar exits 2 for a corrupt archive and
     * also for a full disk or a permission refusal. Needles are from GNU tar 1.35 and UnZip 6.00.
     */
    private static function mentionsBrokenArchive(ServerOpsResult $result): bool
    {
        $output = $result->errorOutput()."\n".$result->output();

        foreach ([
            'not in gzip format',
            'unexpected end of file',
            'invalid compressed data',
            'does not look like a tar archive',
            'Unexpected EOF in archive',
            'End-of-central-directory signature not found',
            'cannot find zipfile directory',
        ] as $needle) {
            if (stripos($output, $needle) !== false) {
                return true;
            }
        }

        return false;
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
     * Both failures exit 2 and say, inside a much longer report:
     *
     *   - Root composer.json requires php ^9.0 but your php version (8.4.23)
     *     does not satisfy that requirement.
     *   - Root composer.json requires PHP extension ext-foo * but it is
     *     missing from your system. Install or enable PHP's foo extension.
     *
     * Matched on the distinctive tail of each, not on "requirements could not be resolved", which
     * composer also prints for an ordinary conflict between two packages. A *dependency* needing a
     * newer PHP ends in the same "does not satisfy that requirement", so the phrase separates
     * platform from package.
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
     * Each registry words this differently:
     *
     *   - Docker Hub: `pull access denied for <repo>, repository does not
     *     exist or may require 'docker login'`
     *   - GHCR:       `error from registry: denied`
     *   - ECR:        `pull access denied, repository does not exist or may
     *     require authorization: authorization failed: no basic auth
     *     credentials`
     *   - registry v2 (GitLab, Harbor, self-hosted): `unauthorized:
     *     authentication required` — from the registry v2 spec, the one needle here not observed
     *     on a real box.
     *
     * `from registry: denied` rather than a bare `denied`, which appears in unrelated daemon
     * errors.
     *
     * **The reason deliberately does not claim the image is private.** Docker Hub answers "does
     * not exist" and "exists but is yours" with the same sentence, so a typo in a public image name
     * lands here too; the wording this maps to names both.
     *
     * Left unclassified because no credential fixes them: a tag that does not exist on a
     * repository that does (`not found`), and an unreachable registry (`dial tcp: lookup … no
     * such host`).
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
     * A different failure from {@see self::mentionsRegistryAuth()} with a different fix: "no
     * credential configured" sends somebody to the Docker page, "the credential was refused" sends
     * them to rotate a token.
     *
     * Needles:
     *
     *   - Docker Hub: `authentication required - incorrect username or
     *     password`
     *   - self-hosted registry v2: `unexpected status from HEAD request to
     *     http://…/v2/…/manifests/1: 401 Unauthorized`
     *
     * `401 Unauthorized` is narrow enough here: in a container pull it is the registry declining
     * the credentials in play. The whole HEAD sentence is not matched, as Docker may reword it.
     *
     * The token itself is never in this text (Docker echoes only the USERNAME back in the Hub
     * variant), but the raw output must still not reach the API: only the reason code does.
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
     * Matched against both streams: the gyp transcript can land on either. `make` and `g++` by
     * name rather than a looser "gyp ERR", which gyp also prints for compile errors, a different
     * problem with a different fix.
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
