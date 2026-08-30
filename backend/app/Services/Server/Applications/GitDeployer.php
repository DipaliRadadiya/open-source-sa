<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Models\Worker;
use App\Services\Git\GitProviderManager;
use App\Services\Server\Runtimes\NodeRuntime;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
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
 * Public repositories skip all of this — no credential is involved at all,
 * which makes them the safer path by construction.
 */
class GitDeployer
{
    public function __construct(
        private ServerOps $serverOps,
        private GitProviderManager $providers,
        private NodeRuntime $node,
        private ProcessSupervisor $supervisor,
        private ProvisionProgress $progress,
        private DeploymentRecorder $recorder,
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
    public function deploy(Application $application, string $documentRoot): array
    {
        $credentialFile = null;

        $this->progress->open($application);

        try {
            // Deploy directly into the document root. Every site type uses the
            // same flat `/home/<user>/<slug>/public_html/<web_root>` structure,
            // and `ApplicationProvisioner` creates and chowns that directory
            // before this method is called — which for a git site it did not
            // do at all until provisioning stopped branching on the site type.
            $this->trustDocumentRoot($documentRoot);

            $credentialFile = $this->writeCredential($application);
            $remote = $this->remoteUrl($application);
            $branch = $application->branch ?: 'main';

            // git init + fetch + reset-hard into the document root.
            // No credential survives past this block; the remote stays clean.
            $this->run('init', null, [
                'git', 'init', '--quiet', '--initial-branch', $branch, $documentRoot,
            ]);

            // Unchecked on purpose: on every deploy after the first, `origin`
            // already exists and this fails. That is expected, not an error.
            $this->serverOps->run(
                ['git', '-C', $documentRoot, 'remote', 'add', 'origin', $remote],
                ['feature' => 'application', 'op' => 'git.remote_add'],
            );

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
            $this->run('init', null, [
                'git', '-C', $documentRoot, 'remote', 'set-url', 'origin', $remote,
            ]);

            $this->run('fetch', $credentialFile, [
                'git', '-C', $documentRoot, 'fetch', '--depth', '1', 'origin', $branch,
            ]);

            $this->run('checkout', null, [
                'git', '-C', $documentRoot, 'reset', '--hard', 'FETCH_HEAD',
            ]);

            $commit = $this->currentCommit($documentRoot);

            // Site is owned by its Linux user. Without this git operations as root
            // inside a non-root-owned directory fail with "dubious ownership".
            $this->run('set_ownership', null, [
                'chown', '-R',
                "{$application->systemUser->username}:{$application->systemUser->username}",
                $documentRoot,
            ]);

            // Before the deploy script, which is where `php artisan migrate`
            // lives: it reads the file this seeds.
            $this->seedEnvironment($application, $documentRoot);

            if (filled($this->script($application))) {
                $this->runScript($application, $documentRoot);
            }

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
            // be broken. Curl the deployed URL and treat a non-2xx as a failed deploy,
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
                ...$this->commitDetails($documentRoot),
            ];
        } finally {
            // Always — a failed deploy must not leave a credential on disk.
            if ($credentialFile !== null) {
                $this->serverOps->run(
                    ['rm', '-f', $credentialFile],
                    ['feature' => 'application', 'op' => 'remove_credential'],
                );
            }
        }
    }

    /**
     * Curl the deployed site to confirm it responds with a 2xx.
     *
     * A non-2xx means the deploy — however clean its script exit code — left the
     * site broken. The user sees "deployed" and "broken" as the same event, so
     * the panel should too.
     *
     * @throws ProvisioningFailedException
     */
    private function verifyDeploy(Application $application): void
    {
        $url = 'http://'.$application->domain;

        // --silent --show-error: no progress output, errors still visible
        // --max-time 5: hard timeout, do not hang the deploy
        // --location --max-redirs 1: follow one redirect (http→https most common)
        // --write-out '%{http_code}': extract the final status code
        // --output /dev/null: discard body, we only care about the status
        // -o /dev/null: same as above, compatible with curl < 7.30
        $result = $this->serverOps->run(
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

        $code = (int) trim($result->output());

        if ($code >= 200 && $code < 300) {
            return; // Healthy.
        }

        // Record the failure on the application so the UI can show what happened
        // without the user needing to dig into the server-ops log.
        $application->update([
            'failed_step' => 'verify',
            'reference' => $result->reference,
        ]);

        throw new ProvisioningFailedException(
            'verify',
            $result->reference,
            "curl {$url} returned HTTP {$code}",
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
     * A 0600 credential file git reads through the `store` helper. Returns null
     * for a public repository, where no credential is needed at all.
     */
    private function writeCredential(Application $application): ?string
    {
        if ($application->git_account_id === null) {
            return null;
        }

        $account = $application->gitAccount;
        $username = $this->providers->driver($account->provider)->credentialUsername();
        $remote = parse_url($this->remoteUrl($application));
        $host = ($remote['host'] ?? '').(isset($remote['port']) ? ':'.$remote['port'] : '');

        $path = rtrim((string) config('server.git_credential_dir', sys_get_temp_dir()), '/')
            .'/git-'.Str::uuid();

        // Written through stdin, never as a command argument.
        $write = $this->serverOps->run(
            ['tee', $path],
            ['feature' => 'application', 'op' => 'write_credential', 'application' => $application->id],
            input: 'https://'.rawurlencode($username).':'.rawurlencode($account->token)."@{$host}\n",
        );

        if ($write->failed()) {
            throw new ProvisioningFailedException('write_credential', $write->reference);
        }

        $this->serverOps->run(
            ['chmod', '0600', $path],
            ['feature' => 'application', 'op' => 'chmod_credential'],
        );

        return $path;
    }

    /**
     * @param  array<int, string>  $command
     *
     * @throws ProvisioningFailedException
     */
    private function run(string $step, ?string $credentialFile, array $command): ServerOpsResult
    {
        if ($credentialFile !== null) {
            // `-c` values are parsed by git itself, not a shell. The path is
            // visible in `ps`; the token inside the file is not.
            array_splice($command, 1, 0, [
                '-c', "credential.helper=store --file={$credentialFile}",
                '-c', 'credential.interactive=never',
            ]);
        }

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
        $php = 'php'.($application->php_version ?: '');

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
            'if [ -f "$root/artisan" ] && [ "$env" = "$root/.env" ] && ! grep -q "^APP_KEY=base64:." "$env"; then',
            '  '.$php.' "$root/artisan" key:generate --force --no-interaction',
            'fi',
        ]);

        $this->run('seed_env', null, [
            'runuser', '-u', $application->systemUser->username, '--',
            'sh', '-c', $script,
        ]);
    }

    private function runScript(Application $application, string $documentRoot): void
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
        $script = implode("\n", [
            'set -e',
            $this->nodePath($application),
            'cd '.escapeshellarg($documentRoot),
            $this->expand($this->script($application), $application, $documentRoot),
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

        if ($result->failed()) {
            throw new ProvisioningFailedException('script', $result->reference);
        }

        $this->progress->record('script');
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
        return strtr($script, [
            '{path}' => $documentRoot,
            '{branch}' => $application->branch ?: 'main',
            '{domain}' => (string) $application->domain,
        ]);
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
    public function commitDetails(string $documentRoot): array
    {
        $result = $this->serverOps->run(
            ['git', '-C', $documentRoot, 'log', '-1', '--pretty=format:%s%n%an'],
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
     * Every git command here runs as root — `git` is in the panel's
     * privilege-elevation list, because `init`/`fetch`/`reset` on an
     * application's directory cannot work as the unprivileged panel user.
     * But `$documentRoot` is owned by the site's own system user (provisioning
     * chowns it before any code arrives, and this method's own caller chowns
     * it again after every deploy) — so root operating on it trips git's
     * dubious-ownership protection (>=2.35.2, post-CVE-2022-24765): the very
     * next git command against a directory `git init` (as root) just created
     * inside a non-root-owned parent refuses outright with "fatal: detected
     * dubious ownership", and on a redeploy, `fetch`/`reset` hit the same
     * wall against the same non-root ownership left by the previous deploy's
     * own chown. Adding a `safe.directory` exception for this application's
     * document root, once, exempts every git command below from the check —
     * same fix, same reasoning, as install.sh's own equivalent for the
     * panel's own checkout.
     */
    private function trustDocumentRoot(string $documentRoot): void
    {
        $configured = $this->serverOps->run(
            ['git', 'config', '--global', '--get-all', 'safe.directory'],
            ['feature' => 'application', 'op' => 'git.safe_directory_check'],
        );

        $trusted = preg_split('/\r?\n/', trim($configured->output())) ?: [];

        if (in_array($documentRoot, $trusted, true)) {
            return;
        }

        $this->serverOps->run(
            ['git', 'config', '--global', '--add', 'safe.directory', $documentRoot],
            ['feature' => 'application', 'op' => 'git.safe_directory'],
        );
    }

    private function currentCommit(string $documentRoot): ?string
    {
        $result = $this->serverOps->run(
            ['git', '-C', $documentRoot, 'rev-parse', 'HEAD'],
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
}
