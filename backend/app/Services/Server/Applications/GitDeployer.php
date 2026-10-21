<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Models\Worker;
use App\Services\Git\GitProviderManager;
use App\Services\Server\Php\PhpShim;
use App\Services\Server\Runtimes\NodeRuntime;
use App\Services\Server\Runtimes\PhpRuntime;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fetches an application's code with git.
 *
 * The whole design here is about one thing: **the token must not end up
 * anywhere durable.** The obvious approach is the wrong one —
 *
 *     git clone https://user:TOKEN@github.com/owner/repo.git
 *
 * leaks twice: the token is visible in `ps` while the command runs, and git
 * writes the whole URL into `.git/config`, where it then sits in plaintext
 * inside the user's own web directory forever.
 *
 * So instead: the remote is stored clean, and the credential is handed to git
 * through a 0600 file that exists only for the length of the command. The file
 * path is on the command line; the token never is.
 *
 * And git runs **as the site's own user** inside the site's repository, never
 * as root (FS-C22 #1). The repository's `.git/config` is the site user's to
 * write, and git executes some of what it says — `core.fsmonitor`,
 * `core.sshCommand`, hooks — so root running git there ran the site user's
 * commands as root on the next deploy or webhook.
 *
 * Public repositories skip all of this — no credential is involved at all,
 * which makes them the safer path by construction.
 */
class GitDeployer
{
    public function __construct(
        private ServerOps $serverOps,
        private GitProviderManager $providers,
        private NodeRuntime $node,
        private PhpRuntime $php,
        private ProcessSupervisor $supervisor,
        private ProvisionProgress $progress,
        private DeploymentRecorder $recorder,
        private PhpShim $shim,
        private SecretFilePrivacy $secretPrivacy,
        private WebServerManager $webServers,
    ) {}

    /**
     * Steps land on the application as they complete — a build is the longest
     * thing this panel does on a live site, and the user watching it deserves
     * to see where it is rather than an empty list until it ends.
     *
     * @return array{steps: array<int, string>, commit: ?string}
     *
     * @throws ProvisioningFailedException
     */
    /**
     * How long to keep asking, and how long to wait between asks.
     *
     * Four probes over roughly fourteen seconds. Long enough for a web server
     * to notice a document root that has just appeared and for a restarted
     * process to bind its port; short enough that a genuinely broken deploy is
     * still reported while the person is looking at the screen.
     */
    private const VERIFY_BACKOFF_MS = [0, 2000, 4000, 8000];

    /**
     * @return array<int, int> the schedule, overridable so the test suite does
     *                         not spend fourteen real seconds proving it waits
     */
    private function verifyBackoff(): array
    {
        $configured = config('server.verify_backoff_ms');

        return is_array($configured) && $configured !== [] ? $configured : self::VERIFY_BACKOFF_MS;
    }

    public function deploy(Application $application, string $documentRoot): array
    {
        $credentialDir = null;
        $handedOver = false;

        $this->progress->open($application);

        try {
            // Deploy directly into the document root. Every site type uses the
            // same flat `/home/<user>/<slug>/public_html/<web_root>` structure,
            // and `ApplicationProvisioner` creates and chowns that directory
            // before this method is called — which for a git site it did not
            // do at all until provisioning stopped branching on the site type.
            $this->reclaimGitDirectory($application, $documentRoot);

            $credentialDir = $this->writeSiteCredential($application);
            $credentialFile = $credentialDir === null ? null : $credentialDir.'/credentials';
            $remote = $this->remoteUrl($application);
            $branch = $application->branch ?: 'main';

            // git init + fetch + reset-hard into the document root.
            // No credential survives past this block; the remote stays clean.
            $this->run('init', $this->git($application, null, [
                'init', '--quiet', '--initial-branch', $branch, $documentRoot,
            ]));

            // Added only when it is missing. It used to be run every time and
            // left to fail, which on every deploy after the first wrote
            // "remote origin already exists" into the log as an error
            // (bug #71) — noise that buries the real failures. `git remote`
            // lists and always succeeds.
            $remotes = $this->serverOps->run(
                $this->git($application, null, ['-C', $documentRoot, 'remote']),
                ['feature' => 'application', 'op' => 'git.remote_list'],
            );

            if (! in_array('origin', preg_split('/\s+/', trim($remotes->output())) ?: [], true)) {
                $this->run('init', $this->git($application, null, [
                    '-C', $documentRoot, 'remote', 'add', 'origin', $remote,
                ]));
            }

            // **Before the fetch, not after it.**
            //
            // `remote add` above is a no-op on an existing checkout, so when
            // the remote changes it is this line that moves it. It used to run
            // after `reset --hard`, which was harmless only while the URL could
            // never change: the account was fixed at creation. Now that a site
            // can be re-pointed at a different account, that ordering meant the
            // first deploy fetched from the OLD remote and checked out the old
            // repository's code, then corrected the URL for next time.
            //
            // Succeeding while serving the wrong repository is worse than
            // failing, because nothing looks wrong.
            $this->run('init', $this->git($application, null, [
                '-C', $documentRoot, 'remote', 'set-url', 'origin', $remote,
            ]));

            $this->run('fetch', $this->git($application, $credentialFile, [
                '-C', $documentRoot, 'fetch', '--depth', '1', 'origin', $branch,
            ]));

            $this->run('checkout', $this->git($application, null, [
                '-C', $documentRoot, 'reset', '--hard', 'FETCH_HEAD',
            ]));

            // Give the checked-out branch an upstream. `git init` + `fetch` +
            // `reset` leaves none, so a deploy script that says a bare
            // `git pull` — the commonest one there is — died on the first
            // deploy with "There is no tracking information for the current
            // branch". `fetch origin <branch>` has just written
            // `origin/<branch>`, so this cannot point at a missing ref.
            // Unchecked: the checkout above already succeeded, and a script
            // that never mentions git does not need this.
            $this->serverOps->run(
                $this->git($application, null, ['-C', $documentRoot, 'branch', '--set-upstream-to=origin/'.$branch]),
                ['feature' => 'application', 'op' => 'git.set_upstream'],
            );

            $commit = $this->currentCommit($application, $documentRoot);
            $details = $this->commitDetails($application, $documentRoot);

            // Recorded here rather than only on success. Everything below this
            // line can fail — ownership, the deploy script, the restarts, the
            // verify — and all of it fails with this revision already checked
            // out. A failed deploy that cannot say which commit it was running
            // is missing the one fact somebody debugging it needs.
            $this->recorder->commit($commit, $details['message'], $details['author']);

            // Site is owned by its Linux user. git now runs as that user and
            // leaves nothing root-owned, but this stays: it is also what hands
            // over a tree an older, root-run deploy left behind.
            $this->run('set_ownership', [
                'chown', '-R',
                "{$application->systemUser->username}:{$application->systemUser->username}",
                $documentRoot,
            ]);
            $handedOver = true;

            // Before the deploy script, which is where `php artisan migrate`
            // lives: it reads the file this seeds.
            $this->seedEnvironment($application, $documentRoot);
            // The seed is a copy of the repository's example, 0644.
            $this->secretPrivacy->narrow($application);

            if (filled($this->script($application))) {
                $this->runScript($application, $documentRoot);
            } elseif ($this->requiresComposerPackages($documentRoot, $application)) {
                // Nothing written to run, and a composer project that cannot
                // serve without its packages: install them, rather than fail
                // the check below. See `composer_install` in config/server.php.
                $this->runScript($application, $documentRoot, (string) config('server.deployments.composer_install'));
            } elseif (($install = $this->nodeInstall($documentRoot, $application)) !== null) {
                // The same gap for a Node application (RC-2): no script, so
                // nothing ran `npm ci`, and the first start died on
                // MODULE_NOT_FOUND.
                $this->runScript($application, $documentRoot, $install);
            }

            // Before the restarts and before the verify, because this is the
            // question the verify cannot answer. Both orders end in a failed
            // deploy; only this one says why.
            $this->checkDependencies($application, $documentRoot);

            // New code is only live once the process running it has been
            // replaced. A deploy that pulls, builds and leaves the old process
            // serving is the most confusing possible outcome: the panel says
            // deployed, the site says otherwise.
            //
            // `apply` rather than `restart`: provisioning writes the unit but
            // deliberately does not start it, because there was no code yet.
            // This is where there is. It also rewrites the unit first, so a
            // changed port or start command takes effect on the same deploy
            // that changed it rather than the one after.
            if ($this->supervisor->runs($application)) {
                $this->supervisor->apply($application, $documentRoot);

                // The proxy has to point where the process now listens. A port
                // changed in the settings reached the unit on this deploy but
                // not the vhost, so every visitor got 502 (frontend QA FS-C41).
                if ($application->app_port) {
                    $this->republishVhost($application, $documentRoot);
                }

                $this->progress->record('restart_app');
            }

            // And the workers, for exactly the same reason but worse: a queue
            // worker holds the old code in memory indefinitely, so without
            // this the site serves the new version while its background jobs
            // quietly keep running last week's — with nothing anywhere to
            // connect the two.
            if ($this->restartWorkers($application) > 0) {
                $this->progress->record('restart_workers');
            }

            // The script and all restarts succeeded — but the site itself might still
            // be broken. Curl the deployed URL and treat a 5xx as a failed deploy,
            // because to the user "deployed" and "working" are the same thing.
            // Skip for types that have no reachable URL yet.
            if ($application->domain !== null) {
                $this->verifyDeploy($application);
            }

            // Directory size is measured against the deployed document root.
            $this->refreshDirectorySize($application, $documentRoot);

            return [
                'steps' => $this->progress->steps(),
                'commit' => $commit,
                ...$details,
            ];
        } finally {
            // A deploy that failed before the hand-over above (a fetch from a
            // wrong URL or a revoked token) left the `.git` that `git init`
            // made as root, which the site user can then neither repair nor
            // delete (bug #101). Handed over anyway; best effort, the deploy
            // has already failed for its own reason.
            if (! $handedOver && $application->systemUser !== null) {
                $this->serverOps->run(
                    ['chown', '-R', "{$application->systemUser->username}:{$application->systemUser->username}", $documentRoot],
                    ['feature' => 'application', 'op' => 'git.reclaim_ownership'],
                );
            }

            // Always — a failed deploy must not leave a credential on disk.
            if ($credentialDir !== null) {
                $this->removeSiteCredential($application, $credentialDir);
            }
        }
    }

    /**
     * Curl the deployed site to confirm it responds below 500.
     *
     * A 5xx means the deploy — however clean its script exit code — left the
     * site broken. The user sees "deployed" and "broken" as the same event, so
     * the panel should too.
     *
     * @throws ProvisioningFailedException
     */
    /**
     * Ask the site whether it is actually serving, once the deploy is done.
     *
     * Retried, because the first deploy of a site is the one case where a
     * single immediate probe is guaranteed to be unfair. The document root for
     * a framework repository (`public/`) did not exist until the checkout
     * seconds ago, php-fpm and the web server can still be holding the old
     * missing path, and a process that has just been restarted has not
     * finished booting. One curl with a five-second timeout and no second
     * chance failed every first deploy and then passed on the re-run — the
     * deploy was never the problem, the timing of the question was.
     *
     * Unreachable is not the same answer as unhealthy, and only one of them is
     * this deploy's fault. A curl that never connected — DNS that does not
     * resolve yet on a brand-new domain, a firewall, a port — says nothing
     * about the code that was just deployed, and failing the deploy over it
     * tells someone to fix a build that worked. A curl that *did* connect and
     * got a 500 is the failure this check exists to catch, and still fails.
     */
    private function verifyDeploy(Application $application): void
    {
        $url = 'http://'.$application->domain;
        $result = null;
        $code = 0;

        foreach ($this->verifyBackoff() as $waitMs) {
            if ($waitMs > 0) {
                usleep($waitMs * 1000);
            }

            $result = $this->probe($url, $application);
            $code = (int) trim($result->output());

            // Any answer below 500 is a working site (FS-C42): an API with no
            // route at `/` answers 404, an app behind a login 302 or 401.
            // Only a 5xx says the deploy left it broken — the same rule
            // HttpReadinessCheck applies to installs.
            if ($code >= 100 && $code < 500) {
                $this->recorder->step('verify', $result);
                $this->progress->record('verify');

                return;
            }
        }

        $this->recorder->step('verify', $result);

        // Never reached the site at all. Recorded so the build log says so, and
        // deliberately not fatal: the checkout, the script and the restarts all
        // succeeded, and "your DNS has not propagated" is not a deploy failure.
        if ($result->exitCode() !== 0) {
            $this->progress->record('verify_unreachable');

            return;
        }

        // Connected, and the site answered with something that is not success.
        // That is the case this check was added for, so it still fails.
        $application->update([
            'failed_step' => 'verify',
            'reference' => $result->reference,
        ]);

        // A reason code, never free text (FS-C40): the reason becomes a
        // translation key, and "curl … returned HTTP 502" showed as a raw
        // `application.failure_reason.curl …` key in every language. The URL
        // and status are in the deployment's own log under the reference.
        throw new ProvisioningFailedException('verify', $result->reference, 'verify_http');
    }

    /**
     * One probe. Split out so the retry loop above reads as a loop.
     *
     * --silent --show-error: no progress output, errors still visible
     * --max-time 5: hard timeout, do not hang the deploy
     * --location --max-redirs 1: follow one redirect (http→https most common)
     * --write-out '%{http_code}': extract the final status code
     * --output /dev/null: discard body, we only care about the status
     */
    private function probe(string $url, Application $application): ServerOpsResult
    {
        return $this->serverOps->run(
            [
                'curl', '--silent', '--show-error',
                '--max-time', '5',
                '--location', '--max-redirs', '1',
                '--write-out', '%{http_code}',
                '--output', '/dev/null',
                $url,
            ],
            ['feature' => 'application', 'op' => 'verify_deploy', 'application' => $application->id],
        );
    }

    /**
     * Recompute the directory size and persist it on the application record.
     *
     * Run after every successful deploy so the file manager does not hit the disk
     * on every browse. Also recomputes if the cached value is stale (null = never
     * computed). Uses `-k` to get 1K-block output stable across systems.
     */
    private function refreshDirectorySize(Application $application, string $documentRoot): void
    {
        $result = $this->serverOps->run(
            ['du', '-sk', $documentRoot],
            ['feature' => 'application', 'op' => 'directory_size', 'application' => $application->id],
        );

        if (! $result->ok) {
            return; // Cannot determine — leave the cache as-is.
        }

        // `du -sk` output is "{kilobytes}\t{path}", so multiply to get bytes.
        $parts = preg_split('/\s+/', trim($result->output()));
        $kilobytes = (int) ($parts[0] ?? 0);
        $bytes = $kilobytes * 1024;

        // The timestamp goes with it, always. Writing the size alone left a
        // fresh number carrying whenever the file browser last happened to
        // measure — or no date at all — and a size with no date reads as
        // current. That is the whole reason the column exists.
        $application->updateQuietly([
            'directory_size_bytes' => $bytes,
            'directory_size_updated_at' => now(),
        ]);
    }

    /**
     * Can this repository be reached, and does the branch exist in it?
     *
     * Null when both hold, or the reason: `repository_unreachable` (wrong
     * URL, no access, or the account's token cannot see it),
     * `host_unreachable` (the server could not reach the git host at all)
     * or `branch_missing`. Asked before anything is created or saved: a site
     * created from a wrong URL used to exist anyway, report "Running", and
     * fail its first deploy with no reason (bug #101); a branch was saved
     * unchecked and broke the next deploy (bug #71).
     *
     * The same remote and credential the deploy itself uses, so a probe that
     * passes is a fetch that can. `ls-remote` downloads nothing, never
     * prompts (credential.interactive=never, GIT_TERMINAL_PROMPT=0) and is
     * bounded by a short timeout.
     */
    public function checkRemoteBranch(Application $application): ?string
    {
        $branch = $application->branch ?: 'main';
        $credentialDirectory = null;

        try {
            $credentialDirectory = $this->writeCredential($application);

            $command = ['git'];

            if ($credentialDirectory !== null) {
                array_push($command, '-c', "credential.helper=store --file={$credentialDirectory}/credentials", '-c', 'credential.interactive=never');
            }

            array_push($command, 'ls-remote', '--heads', $this->remoteUrl($application), 'refs/heads/'.$branch);

            $result = $this->serverOps->run(
                $command,
                ['feature' => 'application', 'op' => 'git.probe'],
                timeout: (int) config('server.git_probe_timeout', 20),
                env: ['GIT_TERMINAL_PROMPT' => '0'],
            );
        } catch (ProvisioningFailedException) {
            // The credential could not be written privately, so git never ran
            // with it: no answer either way, and the probe stays out of the
            // way rather than refusing a site.
            return null;
        } finally {
            // Only a directory the writer itself made. `rm` does not follow a
            // symlink given as its operand.
            if ($credentialDirectory !== null) {
                $this->serverOps->run(['rm', '-rf', $credentialDirectory], ['feature' => 'application', 'op' => 'remove_credential']);
            }
        }

        if ($result->ok) {
            return str_contains($result->output(), 'refs/heads/'.$branch) ? null : 'branch_missing';
        }

        $error = $result->errorOutput();

        return preg_match('/Could not resolve host|Connection timed out|Failed to connect|Network is unreachable/i', $error) === 1 || $result->timedOut
            ? 'host_unreachable'
            : 'repository_unreachable';
    }

    /**
     * The clean remote — never carries credentials.
     */
    public function remoteUrl(Application $application): string
    {
        if ($application->git_account_id === null) {
            return (string) $application->repository_url;
        }

        $account = $application->gitAccount;

        // The web host, not the API host — you cannot clone from
        // api.github.com. Self-hosted GitLab serves both from one host.
        $host = match ($account->provider) {
            'github' => 'https://github.com',
            'bitbucket' => 'https://bitbucket.org',
            default => rtrim($account->host ?: 'https://gitlab.com', '/'),
        };

        return "{$host}/{$application->repository}.git";
    }

    /**
     * The probe's credential: root's, since the probe runs as root with no
     * repository and no site user yet. Returns the directory (the file is
     * `credentials` inside it), or null for a public repository. A deploy
     * uses {@see writeSiteCredential()}.
     *
     * Private from the moment it exists, never made private afterwards. This
     * used to `tee` a file straight into /tmp under the default umask and
     * `chmod 0600` it after: the token sat world-readable in between — a
     * watcher on a test server caught it at 0644 — and a failed chmod went
     * unnoticed. Now `mkdir -m 0700` makes a directory only root can enter
     * (mkdir without `-p` fails on any path already there, symlink included,
     * so nothing planted at the name is followed, written or later removed),
     * and the umask is set before the shell opens the file inside it, so the
     * file is 0600 from open(2). The token arrives on stdin, and `cat` echoes
     * nothing back into a log the way `tee` would.
     *
     * @throws ProvisioningFailedException
     */
    private function writeCredential(Application $application): ?string
    {
        if ($application->git_account_id === null) {
            return null;
        }

        $context = ['feature' => 'application', 'op' => 'write_credential', 'application' => $application->id];
        $directory = $this->credentialBase().'/git-'.Str::uuid();

        $made = $this->serverOps->run(['mkdir', '-m', '0700', $directory], $context);

        // Not ours, whatever is at that path: nothing to remove.
        if ($made->failed()) {
            throw new ProvisioningFailedException('write_credential', $made->reference);
        }

        $write = $this->serverOps->run(
            ['sh', '-c', 'umask 077 && cat > "$1"', 'sh', $directory.'/credentials'],
            $context,
            input: $this->credentialLine($application),
        );

        if ($write->failed()) {
            $this->serverOps->run(['rm', '-rf', $directory], ['feature' => 'application', 'op' => 'remove_credential']);

            throw new ProvisioningFailedException('write_credential', $write->reference);
        }

        return $directory;
    }

    /**
     * The credential for a deploy, in a directory only the site user can
     * enter, since the site user is who runs git now. Returns the directory
     * (the file is `credentials` inside it), or null for a public repository.
     *
     * Everything is done *as* the site user, root never writes here: the
     * directory is theirs, and root writing a file into a directory somebody
     * else owns follows whatever symlink they have put at that name. `mkdir`
     * without `-p` fails on a path that already exists, so nothing can be
     * waiting at it either. The token arrives on stdin, and `cat` echoes
     * nothing back into a log the way `tee` would.
     *
     * The site user can read the token while the fetch runs — git needs it,
     * and git is theirs. Nothing outlives the deploy.
     *
     * @throws ProvisioningFailedException
     */
    private function writeSiteCredential(Application $application): ?string
    {
        if ($application->git_account_id === null) {
            return null;
        }

        $context = ['feature' => 'application', 'op' => 'write_credential', 'application' => $application->id];
        $directory = $this->credentialBase().'/git-'.Str::uuid();

        $made = $this->serverOps->run($this->asSiteUser($application, ['mkdir', '-m', '0700', $directory]), $context);

        if ($made->failed()) {
            throw new ProvisioningFailedException('write_credential', $made->reference);
        }

        $write = $this->serverOps->run(
            $this->asSiteUser($application, ['sh', '-c', 'umask 077 && cat > "$1"', 'sh', $directory.'/credentials']),
            $context,
            input: $this->credentialLine($application),
        );

        if ($write->failed()) {
            $this->removeSiteCredential($application, $directory);

            throw new ProvisioningFailedException('write_credential', $write->reference);
        }

        return $directory;
    }

    /**
     * As the site user first — it is their directory. Root only when that
     * fails (the account went away mid-deploy, say); `rm -r` does not follow
     * a symlink it meets, so root cannot be steered elsewhere by one.
     */
    private function removeSiteCredential(Application $application, string $directory): void
    {
        $context = ['feature' => 'application', 'op' => 'remove_credential'];

        if ($this->serverOps->run($this->asSiteUser($application, ['rm', '-rf', $directory]), $context)->failed()) {
            $this->serverOps->run(['rm', '-rf', $directory], $context);
        }
    }

    private function credentialBase(): string
    {
        return rtrim((string) config('server.git_credential_dir', sys_get_temp_dir()), '/');
    }

    /**
     * One `store`-helper line for the account's host.
     */
    private function credentialLine(Application $application): string
    {
        $account = $application->gitAccount;
        $username = $this->providers->driver($account->provider)->credentialUsername();
        $remote = parse_url($this->remoteUrl($application));
        $host = ($remote['host'] ?? '').(isset($remote['port']) ? ':'.$remote['port'] : '');

        return 'https://'.rawurlencode($username).':'.rawurlencode($account->token)."@{$host}\n";
    }

    /**
     * A git command run as the site user, with the deploy's credential when
     * there is one.
     *
     * `-c` values are parsed by git itself, not a shell. The path is visible
     * in `ps`; the token inside the file is not. The empty helper comes first
     * because it clears every helper configured before the command line —
     * including one in the repository's own config, which the site user
     * writes — so the token goes to this helper and to no other.
     *
     * @param  array<int, string>  $arguments
     * @return array<int, string>
     */
    private function git(Application $application, ?string $credentialFile, array $arguments): array
    {
        $command = ['git'];

        if ($credentialFile !== null) {
            array_push(
                $command,
                '-c', 'credential.helper=',
                '-c', "credential.helper=store --file={$credentialFile}",
                '-c', 'credential.interactive=never',
            );
        }

        return $this->asSiteUser($application, [...$command, ...$arguments]);
    }

    /**
     * @param  array<int, string>  $command
     * @return array<int, string>
     */
    private function asSiteUser(Application $application, array $command): array
    {
        return array_merge(['runuser', '-u', $application->systemUser->username, '--'], $command);
    }

    /**
     * @param  array<int, string>  $command
     *
     * @throws ProvisioningFailedException
     */
    private function run(string $step, array $command): ServerOpsResult
    {
        $result = $this->serverOps->run(
            $command,
            ['feature' => 'application', 'op' => "git.{$step}"],
            timeout: (int) config('server.git_timeout', 300),
        );

        // The deployment row gets the output; ProvisionProgress gets only the
        // step name, because it is also used by provisioning where there is no
        // row to write to.
        $this->recorder->step($step, $result);

        if ($result->failed()) {
            throw new ProvisioningFailedException($step, $result->reference);
        }

        $this->progress->record($step);

        return $result;
    }

    /**
     * The build command is whatever the user typed, so it runs **as the site's
     * own user**, never as the panel. A shell is unavoidable here (users write
     * `npm ci && npm run build`), which makes dropping privileges the control
     * that matters: `application:manage` must not be a route to running
     * commands as root.
     *
     * @throws ProvisioningFailedException
     */
    /**
     * Give a fresh checkout a `.env` with something in it.
     *
     * Provisioning `touch`es an empty file so the path exists and belongs to
     * the site user. Nothing then filled it, and a repository does not carry
     * its own `.env` — it is gitignored, by every framework's own instruction.
     * So a git-deployed Laravel site arrived with a zero-byte environment
     * file, no APP_KEY, and a panel screen correctly reporting exactly that
     * while looking, to the user, like the screen had failed to load.
     *
     * Three guards, because this runs on every deploy and not just the first:
     * a file with anything in it is never touched, a repository with no
     * `.env.example` is left alone rather than guessed at, and `key:generate`
     * runs only when it would write to the same file we just seeded — it
     * targets `base_path('.env')` and would otherwise create a second one.
     *
     * As the site user: the file must stay theirs, and a root-owned `.env` is
     * one their own process cannot write.
     */
    private function seedEnvironment(Application $application, string $codeRoot): void
    {
        $script = implode("\n", [
            'set -e',
            'env='.escapeshellarg($application->envPath()),
            'root='.escapeshellarg($codeRoot),
            // `if`, not `[ … ] && exit 0` — under `set -e` a false test at the
            // head of an && list ends the script with its exit code, so the
            // "nothing to do" path would report a failed deploy.
            'if [ -s "$env" ]; then exit 0; fi',
            'if [ ! -f "$root/.env.example" ]; then exit 0; fi',
            'cp "$root/.env.example" "$env"',
            // The example's APP_URL is the framework's development default —
            // `http://localhost` in Laravel's, a port on the visitor's own
            // machine. Replaced, never appended: a repository whose example
            // has no APP_URL is not a Laravel-shaped one, and inventing a key
            // for it would be guessing at somebody else's config format.
            'if grep -q "^APP_URL=" "$env"; then',
            '  sed -i '.escapeshellarg('s|^APP_URL=.*|APP_URL='.$application->url().'|').' "$env"',
            'fi',
            // Not `artisan key:generate`, which is what this used to be and
            // could not work. `artisan` requires `vendor/autoload.php` on its
            // tenth line, and `vendor/` is created by `composer install` —
            // which lives in the *deploy script*, and the deploy script runs
            // after this step. So the first deploy of any Laravel repository
            // died here on a fatal require, while the re-run passed because
            // the `.env` copied a moment ago made the whole step exit early.
            //
            // The key is 32 random bytes base64-encoded, which is exactly what
            // key:generate writes for the default cipher. openssl is on every
            // box this panel supports and needs no application to boot, so the
            // one step that must work before dependencies exist no longer
            // depends on them.
            'if [ "$env" = "$root/.env" ] && ! grep -q "^APP_KEY=base64:." "$env"; then',
            '  if key=$(openssl rand -base64 32 2>/dev/null); then',
            // `|` as the delimiter: base64 is [A-Za-z0-9+/=], so it can carry
            // a `/` but never a `|`. `&` is sed's "the whole match" and is not
            // in the alphabet either.
            '    if grep -q "^APP_KEY=" "$env"; then',
            '      sed -i "s|^APP_KEY=.*|APP_KEY=base64:$key|" "$env"',
            '    else',
            '      printf \'\\nAPP_KEY=base64:%s\\n\' "$key" >> "$env"',
            '    fi',
            '  fi',
            'fi',
        ]);

        $this->run('seed_env', $this->asSiteUser($application, ['sh', '-c', $script]));
    }

    private function runScript(Application $application, string $documentRoot, ?string $script = null): void
    {
        // `set -e` so the script stops at the first failing line. Without it a
        // failed `composer install` is followed cheerfully by `php artisan
        // migrate`, and the deploy reports success on a half-updated site —
        // which is worse than the failure it was hiding.
        // `set -e` first, before anything else runs — including the `cd`. It
        // makes the script stop at the first failing line; without it a failed
        // `composer install` is followed cheerfully by `php artisan migrate`,
        // and the deploy reports success on a half-updated site. Putting it
        // after the `cd` would leave the one command whose failure matters most
        // unguarded: running the rest of a deploy script in the wrong directory.
        $body = $script ?? (string) $this->script($application);

        // Before anything runs: a `{PHP81}` with no PHP 8.1 behind it would
        // otherwise reach the shell as text and fail halfway through.
        if (($missing = app(DeployScriptPhp::class)->missing($body)) !== []) {
            $reference = (string) Str::uuid();
            Log::channel('server-ops')->warning('deploy.script_php_missing', [
                'feature' => 'application', 'op' => 'git.script', 'application' => $application->id,
                'variables' => $missing, 'reference' => $reference,
            ]);

            throw new ProvisioningFailedException('script', $reference, 'script_php_missing');
        }

        $script = implode("\n", [
            'set -e',
            $this->nodePath($application),
            $this->phpPath($application),
            'cd '.escapeshellarg($documentRoot),
            $this->expand($body, $application, $documentRoot),
        ]);

        $result = $this->serverOps->run(
            [
                'runuser', '-u', $application->systemUser->username, '--',
                'sh', '-c', $script,
            ],
            ['feature' => 'application', 'op' => 'git.script', 'application' => $application->id],
            timeout: (int) config('server.build_timeout', 600),
        );

        $this->recorder->step('script', $result);

        // `fromResult`, not the bare constructor. The deploy script is where
        // `composer install` and `npm ci` run, which makes it the step with the
        // most classifiable failures in the whole panel — and it was the one
        // step throwing with no reason attached at all, so the out-of-memory
        // and missing-compiler classifications that exist for the marketplace
        // installers never once applied to a git deploy.
        if ($result->failed()) {
            throw ProvisioningFailedException::fromResult('script', $result);
        }

        $this->progress->record('script');
    }

    /**
     * A composer project must have its dependencies on disk before anything
     * is asked to serve it.
     *
     * The failure this exists for is silent by construction: a site with no
     * deploy script, or with one that never runs `composer install`, checks
     * out cleanly, restarts cleanly, and then answers every request with a
     * fatal on the missing `vendor/autoload.php`. The panel's only verdict was
     * "curl returned HTTP 500" from the verify — correct, and useless, because
     * the one fact needed to fix it is not in it.
     *
     * **Gated on the manifest actually requiring something.** Not on the mere
     * presence of `composer.json`: a repository that carries one only to pin a
     * linter has `require-dev` and no runtime dependencies at all, serves
     * perfectly well with no `vendor/`, and deploys fine today. Failing that
     * site would be this method causing the outage it was written to describe.
     * Platform entries do not count either — `php` and `ext-*` are constraints
     * on the interpreter, not packages that land in `vendor/`.
     *
     * @throws ProvisioningFailedException
     */
    private function checkDependencies(Application $application, string $codeRoot): void
    {
        $context = ['feature' => 'application', 'op' => 'check_dependencies', 'application' => $application->id];

        $manifest = $this->serverOps->run(['cat', $codeRoot.'/composer.json'], $context);

        if ($manifest->failed()) {
            return; // Not a composer project, or unreadable — neither is ours to judge.
        }

        if (! $this->requiresPackages($manifest->output())) {
            return;
        }

        $autoload = $this->serverOps->probe(
            ['test', '-f', $codeRoot.'/vendor/autoload.php'],
            $context,
        );

        if ($autoload->ok) {
            $this->progress->record('dependencies');

            return;
        }

        $this->recorder->step('dependencies', $autoload);

        $application->update([
            'failed_step' => 'dependencies',
            'reference' => $autoload->reference,
        ]);

        throw new ProvisioningFailedException(
            'dependencies',
            $autoload->reference,
            'composer_dependencies_missing',
        );
    }

    /**
     * Does the checkout's `composer.json` require at least one real package?
     * The same question, and the same answer, as {@see checkDependencies()}.
     */
    /**
     * The install + build to run for a Node application nobody wrote a script
     * for, or null when there is nothing it needs (RC-2).
     *
     * Only for an application served by Node — a PHP repository with a
     * `package.json` for its assets deploys today without npm, and must keep
     * doing so — whose `package.json` lists dependencies that are not on disk.
     * The package manager is the one on record, else the one whose lockfile
     * the repository carries; `npm ci` needs a lockfile, so a repository with
     * none gets `npm install`.
     */
    private function nodeInstall(string $codeRoot, Application $application): ?string
    {
        if ($application->serving_profile !== 'node') {
            return null;
        }

        $context = ['feature' => 'application', 'op' => 'check_node_dependencies', 'application' => $application->id];

        $manifest = $this->serverOps->run(['cat', $codeRoot.'/package.json'], $context);
        $decoded = $manifest->ok ? json_decode($manifest->output(), true) : null;

        if (! is_array($decoded) || ! is_array($decoded['dependencies'] ?? null) || $decoded['dependencies'] === []) {
            return null;
        }

        if ($this->serverOps->probe(['test', '-d', $codeRoot.'/node_modules'], $context)->ok) {
            return null;
        }

        $scripts = (array) config('server.deployments.package_manager_scripts', []);
        $manager = $application->package_manager;

        if (! isset($scripts[$manager])) {
            $manager = null;

            foreach (['bun.lock' => 'bun', 'bun.lockb' => 'bun', 'pnpm-lock.yaml' => 'pnpm', 'yarn.lock' => 'yarn', 'package-lock.json' => 'npm'] as $lockfile => $candidate) {
                if ($this->serverOps->probe(['test', '-f', $codeRoot.'/'.$lockfile], $context)->ok) {
                    $manager = $candidate;
                    break;
                }
            }
        }

        return $manager !== null && isset($scripts[$manager])
            ? (string) $scripts[$manager]
            : (string) config('server.deployments.npm_install_without_lockfile', "npm install\nnpm run build --if-present\n");
    }

    private function requiresComposerPackages(string $codeRoot, Application $application): bool
    {
        $manifest = $this->serverOps->run(
            ['cat', $codeRoot.'/composer.json'],
            ['feature' => 'application', 'op' => 'check_dependencies', 'application' => $application->id],
        );

        return $manifest->ok && $this->requiresPackages($manifest->output());
    }

    /**
     * Does this `composer.json` require at least one real package?
     *
     * Malformed JSON answers no. A manifest we cannot parse is not evidence
     * that anything is missing, and this check's whole licence to fail a
     * deploy rests on being certain.
     */
    private function requiresPackages(string $json): bool
    {
        $manifest = json_decode($json, true);

        if (! is_array($manifest) || ! isset($manifest['require']) || ! is_array($manifest['require'])) {
            return false;
        }

        foreach (array_keys($manifest['require']) as $name) {
            $name = strtolower((string) $name);

            if ($name === 'php' || $name === 'hhvm') {
                continue;
            }

            // `ext-`/`lib-` are the interpreter's own extensions and libraries;
            // `composer-*-api` are composer's, satisfied by composer itself.
            if (str_starts_with($name, 'ext-') || str_starts_with($name, 'lib-') || str_starts_with($name, 'composer-')) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * What to run after checkout.
     *
     * `deploy_script` when the user has written one, otherwise the old
     * `build_command`. Kept as a fallback rather than migrated, so an
     * application configured before the Deployment screen existed keeps
     * deploying exactly as it did — a silent change to what runs on someone's
     * production site is not an upgrade.
     */
    public function script(Application $application): ?string
    {
        return filled($application->deploy_script)
            ? $application->deploy_script
            : $application->build_command;
    }

    /**
     * Substitute the placeholders the script may use.
     *
     * The same `{path}` convention the cron command presets already use, so a
     * user who has met one recognises the other.
     */
    private function expand(string $script, Application $application, string $documentRoot): string
    {
        return strtr($script, $this->variables($application, $documentRoot));
    }

    /**
     * What each placeholder becomes in this site's deploy, as the Deployment
     * screen shows it — computed here, by the code that substitutes them, so
     * the screen cannot describe a different value from the one that runs.
     *
     * @return array<string, string>
     */
    public function placeholderValues(Application $application): array
    {
        // The same path DeployApplication hands deploy().
        return $this->variables($application, $application->codePath());
    }

    /**
     * @return array<string, string>
     */
    private function variables(Application $application, string $documentRoot): array
    {
        return [
            // The site's own interpreter, spelled out. `php` on PATH already
            // resolves to it, but a script written before that was true may
            // name a version explicitly, and this is the way to do so without
            // hardcoding a path that changes when the site's version does.
            '{php}' => $this->phpBinary($application),
            '{path}' => $documentRoot,
            '{branch}' => $application->branch ?: 'main',
            '{domain}' => (string) $application->domain,
        ] + app(DeployScriptPhp::class)->aliases();
    }

    /**
     * The commit's subject and author, for the deployment record.
     *
     * Read from the checkout rather than from the webhook payload: the payload
     * describes what was pushed, this describes what is on disk, and when a
     * deploy lands mid-push those differ.
     *
     * @return array{message: ?string, author: ?string}
     */
    public function commitDetails(Application $application, string $documentRoot): array
    {
        $result = $this->serverOps->run(
            $this->git($application, null, ['-C', $documentRoot, 'log', '-1', '--pretty=format:%s%n%an']),
            ['feature' => 'application', 'op' => 'git.commit_details'],
        );

        if ($result->failed()) {
            return ['message' => null, 'author' => null];
        }

        $lines = explode("\n", trim($result->output()));

        return [
            'message' => $lines[0] ?? null,
            'author' => $lines[1] ?? null,
        ];
    }

    /**
     * `export PATH=…;` putting the site's own Node first, or nothing when the
     * site pinned no version.
     *
     * Without it `npm ci && npm run build` runs under whatever `node` the
     * default happens to be. A site pinned to 18 on a box defaulting to 22
     * built with 22 — silently, and only visibly much later, as a runtime
     * error in code that compiled fine.
     *
     * Written into the shell command rather than passed as an environment
     * variable because `runuser` sits in between, and how much of the
     * environment survives that depends on its configuration. This does not.
     */
    private function nodePath(Application $application): string
    {
        if (blank($application->node_version)) {
            return '';
        }

        $bin = dirname($this->node->binaryPath((string) $application->node_version));

        return 'export PATH='.escapeshellarg($bin).':"$PATH"; ';
    }

    /**
     * The site's PHP binary, or the bare name when it cannot be resolved —
     * which is what the script would have used anyway.
     */
    private function phpBinary(Application $application): string
    {
        $version = (string) $application->php_version;

        if (preg_match('/^\d+\.\d+$/', $version) !== 1) {
            return 'php';
        }

        return $this->php->binaryPath($version) ?: 'php';
    }

    /**
     * `export PATH=…;` putting the site's own PHP first, or nothing when it
     * cannot be established.
     *
     * The same bug as {@see nodePath()} and a worse one, because PHP is what
     * `composer install` resolves its platform requirements against. A site
     * set to 8.2 on a box whose default `php` is 8.4 had its dependencies
     * resolved for 8.4 — and composer writes what it resolved against into
     * `vendor/composer/platform_check.php`, which `vendor/autoload.php`
     * requires on its way in. So the deploy succeeded, every step went green,
     * and the site answered **every request with a 500** thrown by composer's
     * own guard, saying a PHP version nobody had chosen was required.
     *
     * That is worse than Node's version of this because nothing in the output
     * points at it: the build log shows a clean `composer install`, and the
     * panel's only verdict is "curl returned HTTP 500".
     *
     * A symlink directory rather than `dirname($binary)`, which is what Node
     * can do and PHP cannot: fnm gives each Node version its own `bin`, while
     * apt puts every PHP in `/usr/bin` under a versioned name. Prepending
     * `/usr/bin` selects nothing. A directory holding one symlink called `php`
     * is the only shape that means "this version" to a `#!/usr/bin/env php`
     * shebang — which is how composer itself is started.
     */
    private function phpPath(Application $application): string
    {
        return $this->shim->exportFor($application);
    }

    /**
     * Hand a `.git` that root made back to the site user before git runs as
     * them. Checkouts made before git ran as the site user — and v7's —
     * may still hold root-owned objects, which the site user could not
     * write, so the first deploy after the upgrade would fail at `fetch`.
     *
     * In place of what was here: a `safe.directory` entry added to root's
     * global config for every document root, which is what let root run git
     * in a directory the site user controls. Entries already written are left
     * where they are; nothing runs git as root in these directories any more.
     *
     * `-h`: a symlink inside is changed itself, never what it points at.
     */
    private function reclaimGitDirectory(Application $application, string $documentRoot): void
    {
        $context = ['feature' => 'application', 'op' => 'git.reclaim_ownership'];

        if (! $this->serverOps->probe(['test', '-d', $documentRoot.'/.git'], $context)->ok) {
            return;
        }

        $owner = $application->systemUser->username;

        $this->serverOps->run(['chown', '-R', '-h', "{$owner}:{$owner}", $documentRoot.'/.git'], $context);
    }

    private function currentCommit(Application $application, string $documentRoot): ?string
    {
        $result = $this->serverOps->run(
            $this->git($application, null, ['-C', $documentRoot, 'rev-parse', 'HEAD']),
            ['feature' => 'application', 'op' => 'git.commit'],
        );

        return $result->ok ? (trim($result->output()) ?: null) : null;
    }

    /**
     * Restart every worker that asked to be restarted on deploy.
     *
     * Failures are logged, not thrown: the deploy itself has already
     * succeeded, and turning "one worker would not come back" into "the
     * deploy failed" would send someone rolling back a release that is fine.
     * The worker's own state reports it honestly on the next screen load.
     */
    private function restartWorkers(Application $application): int
    {
        $workers = Worker::query()
            ->with('application.systemUser')
            ->where('application_id', $application->id)
            ->where('restart_on_deploy', true)
            ->where('enabled', true)
            ->get();

        foreach ($workers as $worker) {
            try {
                app(WorkerSupervisor::class)->restart($worker);
            } catch (Throwable $e) {
                Log::channel('server-ops')->warning('worker restart after deploy failed', [
                    'feature' => 'application',
                    'application' => $application->id,
                    'worker' => $worker->id,
                    'detail' => $e->getMessage(),
                ]);
            }
        }

        return $workers->count();
    }

    /**
     * Rewrite the application's vhost and reload, refusing a config the web
     * server rejects rather than reloading into it.
     */
    private function republishVhost(Application $application, string $documentRoot): void
    {
        $driver = $this->webServers->driver();
        $written = $driver->apply($application, $documentRoot);

        if ($written->failed()) {
            throw new ProvisioningFailedException('write_vhost', $written->reference);
        }

        $tested = $driver->test();

        if ($tested->failed()) {
            throw new ProvisioningFailedException('test_config', $tested->reference);
        }

        $driver->reload();
    }
}
