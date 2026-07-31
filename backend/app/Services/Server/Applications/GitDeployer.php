<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Git\GitProviderManager;
use App\Services\Server\Runtimes\NodeRuntime;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Str;

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
    ) {}

    /**
     * @return array{steps: array<int, string>, commit: ?string}
     *
     * @throws ProvisioningFailedException
     */
    public function deploy(Application $application, string $documentRoot): array
    {
        $credentialFile = null;
        $steps = [];

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
                $steps[] = 'fetch';

                $this->run('checkout', null, [
                    'git', '-C', $documentRoot, 'reset', '--hard', "origin/{$branch}",
                ]);
                $steps[] = 'checkout';
            } else {
                $this->run('clone', $credentialFile, [
                    'git', 'clone', '--depth', '1', '--branch', $branch, $remote, $documentRoot,
                ]);
                $steps[] = 'clone';

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
            $steps[] = 'set_ownership';

            if (filled($application->build_command)) {
                $this->runBuild($application, $documentRoot);
                $steps[] = 'build';
            }

            return ['steps' => $steps, 'commit' => $commit];
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

        if ($result->failed()) {
            throw new ProvisioningFailedException($step, $result->reference);
        }

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
    private function runBuild(Application $application, string $documentRoot): void
    {
        $result = $this->serverOps->run(
            [
                'runuser', '-u', $application->systemUser->username, '--',
                'sh', '-c', $this->nodePath($application).'cd '.escapeshellarg($documentRoot).' && '.$application->build_command,
            ],
            ['feature' => 'application', 'op' => 'git.build', 'application' => $application->id],
            timeout: (int) config('server.build_timeout', 600),
        );

        if ($result->failed()) {
            throw new ProvisioningFailedException('build', $result->reference);
        }
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
}
