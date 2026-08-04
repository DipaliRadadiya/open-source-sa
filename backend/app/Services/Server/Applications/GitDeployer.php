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
            $credentialFile = $this->writeCredential($application);
            $remote = $this->remoteUrl($application);
            $branch = $application->branch ?: 'main';

            $alreadyCloned = $this->isRepository($documentRoot);

            if ($alreadyCloned) {
                // Redeploy: fetch and hard-reset so the working tree matches
                // the branch exactly. Local edits on a deploy target are not
                // something to preserve — they would silently break the next
                // deploy instead.
                $this->run('fetch', $credentialFile, [
                    'git', '-C', $documentRoot, 'fetch', 'origin', $branch, '--depth', '1',
                ]);

                $this->run('checkout', null, [
                    'git', '-C', $documentRoot, 'reset', '--hard', "origin/{$branch}",
                ]);
            } else {
                $this->run('clone', $credentialFile, [
                    'git', 'clone', '--depth', '1', '--branch', $branch, $remote, $documentRoot,
                ]);

                // Store the remote without any credential in it, so nothing
                // sensitive is written into .git/config.
                $this->run('clone', null, [
                    'git', '-C', $documentRoot, 'remote', 'set-url', 'origin', $remote,
                ]);
            }

            $commit = $this->currentCommit($documentRoot);

            $this->run('set_ownership', null, [
                'chown', '-R',
                "{$application->systemUser->username}:{$application->systemUser->username}",
                $documentRoot,
            ]);

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

    private function isRepository(string $documentRoot): bool
    {
        return $this->serverOps->run(
            ['test', '-d', "{$documentRoot}/.git"],
            ['feature' => 'application', 'op' => 'git.detect'],
        )->ok;
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
