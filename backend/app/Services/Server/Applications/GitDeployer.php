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
 * The token must not end up anywhere durable: `git clone https://user:TOKEN@host/…` leaks it in
 * `ps` and in `.git/config`. So the remote is stored clean and the credential reaches git through
 * a 0600 file that lives only as long as the command; the path is on the command line, the token
 * never is.
 *
 * Git runs as the site's own user, never root: its `.git/config` is the site user's to write and
 * git executes parts of it (`core.fsmonitor`, `core.sshCommand`, hooks).
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
     * Steps land on the application as they complete, so a long build shows its progress live.
     *
     * @return array{steps: array<int, string>, commit: ?string}
     *
     * @throws ProvisioningFailedException
     */
    /** Delays in ms between verify probes: about fourteen seconds in total. */
    private const VERIFY_BACKOFF_MS = [0, 2000, 4000, 8000];

    /** @return array<int, int> the schedule, overridable so tests do not really wait */
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
            // Deploy into the document root, already created and chowned by `ApplicationProvisioner`.
            $this->reclaimGitDirectory($application, $documentRoot);

            $credentialDir = $this->writeSiteCredential($application);
            $credentialFile = $credentialDir === null ? null : $credentialDir.'/credentials';
            $remote = $this->remoteUrl($application);
            $branch = $application->branch ?: 'main';

            // git init + fetch + reset-hard; no credential survives this block, the remote stays clean.
            $this->run('init', $this->git($application, null, [
                'init', '--quiet', '--initial-branch', $branch, $documentRoot,
            ]));

            // Only when missing: avoids a "remote origin already exists" error in every later deploy log.
            $remotes = $this->serverOps->run(
                $this->git($application, null, ['-C', $documentRoot, 'remote']),
                ['feature' => 'application', 'op' => 'git.remote_list'],
            );

            if (! in_array('origin', preg_split('/\s+/', trim($remotes->output())) ?: [], true)) {
                $this->run('init', $this->git($application, null, [
                    '-C', $documentRoot, 'remote', 'add', 'origin', $remote,
                ]));
            }

            // Before the fetch: `remote add` is a no-op on an existing checkout, so this moves a
            // changed remote. Fetching from the old one would silently serve the wrong repository.
            $this->run('init', $this->git($application, null, [
                '-C', $documentRoot, 'remote', 'set-url', 'origin', $remote,
            ]));

            $this->run('fetch', $this->git($application, $credentialFile, [
                '-C', $documentRoot, 'fetch', '--depth', '1', 'origin', $branch,
            ]));

            $this->run('checkout', $this->git($application, null, [
                '-C', $documentRoot, 'reset', '--hard', 'FETCH_HEAD',
            ]));

            // Give the branch an upstream (init + fetch + reset leaves none) so a bare `git pull` in a
            // deploy script works. Unchecked: the checkout already succeeded.
            $this->serverOps->run(
                $this->git($application, null, ['-C', $documentRoot, 'branch', '--set-upstream-to=origin/'.$branch]),
                ['feature' => 'application', 'op' => 'git.set_upstream'],
            );

            $commit = $this->currentCommit($application, $documentRoot);
            $details = $this->commitDetails($application, $documentRoot);

            // Recorded now: everything below can fail with this revision already checked out.
            $this->recorder->commit($commit, $details['message'], $details['author']);

            // Also hands over a tree that an older, root-run deploy left behind.
            $this->run('set_ownership', [
                'chown', '-R',
                "{$application->systemUser->username}:{$application->systemUser->username}",
                $documentRoot,
            ]);
            $handedOver = true;

            // Before the deploy script: `php artisan migrate` there reads the file this seeds.
            $this->seedEnvironment($application, $documentRoot);
            // The seed is a copy of the repository's example, 0644.
            $this->secretPrivacy->narrow($application);

            if (filled($this->script($application))) {
                $this->runScript($application, $documentRoot);
            } elseif ($this->requiresComposerPackages($documentRoot, $application)) {
                // No script, and a composer project that needs its packages: install them.
                $this->runScript($application, $documentRoot, (string) config('server.deployments.composer_install'));
            } elseif (($install = $this->nodeInstall($documentRoot, $application)) !== null) {
                // Same gap for a Node application: with no script, nothing ran `npm ci`.
                $this->runScript($application, $documentRoot, $install);
            }

            // Before the restarts and the verify: the verify cannot say why a deploy failed, this can.
            $this->checkDependencies($application, $documentRoot);

            // New code is live only once its process is replaced. `apply`, not `restart`: it also
            // rewrites the unit, and provisioning writes the unit without starting it.
            if ($this->supervisor->runs($application)) {
                $this->supervisor->apply($application, $documentRoot);

                // The vhost must follow the port the process now listens on, or visitors get a 502.
                if ($application->app_port) {
                    $this->republishVhost($application, $documentRoot);
                }

                $this->progress->record('restart_app');
            }

            // Workers too: a queue worker keeps the old code in memory until restarted.
            if ($this->restartWorkers($application) > 0) {
                $this->progress->record('restart_workers');
            }

            // The site may still be broken despite a clean script and restarts: a 5xx fails the
            // deploy. Skipped for types with no reachable URL yet.
            if ($application->domain !== null) {
                $this->verifyDeploy($application);
            }

            $this->refreshDirectorySize($application, $documentRoot);

            return [
                'steps' => $this->progress->steps(),
                'commit' => $commit,
                ...$details,
            ];
        } finally {
            // A failure before the hand-over leaves the root-made `.git`, which the site user can
            // neither repair nor delete. Best effort.
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

    /** @throws ProvisioningFailedException */
    /**
     * Ask the site whether it is serving, once the deploy is done.
     *
     * Retried because the first probe of a first deploy is unfair: the document root may be
     * seconds old, the web server may still hold the old path, a restarted process may still be
     * booting. A curl that never connected (DNS, firewall) says nothing about the deployed code and
     * does not fail the deploy; one that got a 5xx does.
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

            // Below 500 is working: an API answers 404 at `/`, an app behind a login 302 or 401.
            if ($code >= 100 && $code < 500) {
                $this->recorder->step('verify', $result);
                $this->progress->record('verify');

                return;
            }
        }

        $this->recorder->step('verify', $result);

        // Never reached the site: recorded but not fatal (e.g. DNS not propagated); the checkout,
        // script and restarts succeeded.
        if ($result->exitCode() !== 0) {
            $this->progress->record('verify_unreachable');

            return;
        }

        // Connected, but the site answered with a 5xx: the case this check exists to catch.
        $application->update([
            'failed_step' => 'verify',
            'reference' => $result->reference,
        ]);

        // A reason code, never free text: it becomes a translation key. Details are in the deploy log.
        throw new ProvisioningFailedException('verify', $result->reference, 'verify_http');
    }

    /** One probe: 5s hard timeout, one redirect followed (http→https), prints only the status. */
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
     * Recompute the directory size and persist it, so the file manager does not hit the disk on
     * every browse. `-k` gives 1K blocks, stable across systems.
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

        // The timestamp always goes with the size: a size with no date reads as current.
        $application->updateQuietly([
            'directory_size_bytes' => $bytes,
            'directory_size_updated_at' => now(),
        ]);
    }

    /**
     * Can this repository be reached, and does the branch exist in it?
     *
     * Null when both hold, else `repository_unreachable` (wrong URL, no access, token cannot see
     * it), `host_unreachable` or `branch_missing`. Same remote and credential as the deploy;
     * `ls-remote` downloads nothing, never prompts, and has a short timeout.
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
            // The credential could not be written privately: no answer, rather than refusing a site.
            return null;
        } finally {
            // Only a directory the writer made; `rm` does not follow a symlink operand.
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

    /** The clean remote: never carries credentials. */
    public function remoteUrl(Application $application): string
    {
        if ($application->git_account_id === null) {
            return (string) $application->repository_url;
        }

        $account = $application->gitAccount;

        // The web host, not the API host (cannot clone from api.github.com).
        $host = match ($account->provider) {
            'github' => 'https://github.com',
            'bitbucket' => 'https://bitbucket.org',
            default => rtrim($account->host ?: 'https://gitlab.com', '/'),
        };

        return "{$host}/{$application->repository}.git";
    }

    /**
     * The probe's credential, written as root (no site user yet). Returns the directory (the file
     * is `credentials` inside it), or null for a public repository. A deploy uses
     * {@see writeSiteCredential()}.
     *
     * Private from creation: `mkdir -m 0700` without `-p` (a planted path or symlink fails, is never
     * followed) and `umask 077` before the file opens. The token arrives on stdin, and `cat` echoes
     * nothing into a log the way `tee` would.
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
     * The credential for a deploy, in a directory only the site user can enter. Returns the
     * directory (the file is `credentials` inside it), or null for a public repository.
     *
     * Done *as* the site user: root writing into a directory somebody else owns follows any
     * symlink they planted. `mkdir` without `-p` fails on an existing path; the token arrives on
     * stdin, so `cat` echoes nothing into a log. The site user can read it while the fetch runs.
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
     * As the site user first (it is their directory); root only if that fails. `rm -r` does not
     * follow a symlink, so root cannot be steered elsewhere.
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

    /** One `store`-helper line for the account's host. */
    private function credentialLine(Application $application): string
    {
        $account = $application->gitAccount;
        $username = $this->providers->driver($account->provider)->credentialUsername();
        $remote = parse_url($this->remoteUrl($application));
        $host = ($remote['host'] ?? '').(isset($remote['port']) ? ':'.$remote['port'] : '');

        return 'https://'.rawurlencode($username).':'.rawurlencode($account->token)."@{$host}\n";
    }

    /**
     * A git command run as the site user, with the deploy's credential when there is one.
     *
     * The empty `credential.helper=` comes first: it clears every helper configured before it,
     * including one in the repo's own config (writable by the site user), so the token goes to
     * this helper only. The path shows in `ps`; the token in the file does not.
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

        // The deployment row gets the output; ProvisionProgress only the step name.
        $this->recorder->step($step, $result);

        // Classified, so a typo in the repository or branch gets a reason, not just "failed at fetch".
        if ($result->failed()) {
            throw ProvisioningFailedException::fromResult($step, $result);
        }

        $this->progress->record($step);

        return $result;
    }

    /**
     * The build command is user-typed, so it runs as the site's own user: `application:manage`
     * must not be a route to root commands.
     *
     * @throws ProvisioningFailedException
     */
    /**
     * Give a fresh checkout a `.env` with something in it.
     *
     * Repositories do not carry a `.env`, so without this a git-deployed Laravel site gets an
     * empty one and no APP_KEY. Runs on every deploy: a non-empty file is never touched, and no
     * `.env.example` means leave it alone. Runs as the site user so the file stays theirs.
     */
    private function seedEnvironment(Application $application, string $codeRoot): void
    {
        $script = implode("\n", [
            'set -e',
            'env='.escapeshellarg($application->envPath()),
            'root='.escapeshellarg($codeRoot),
            // `if`, not `[ … ] && exit 0`: under `set -e` a false test at the head of an && list aborts.
            'if [ -s "$env" ]; then exit 0; fi',
            'if [ ! -f "$root/.env.example" ]; then exit 0; fi',
            'cp "$root/.env.example" "$env"',
            // The example's APP_URL is a dev default: replaced, never appended (none = not Laravel-shaped).
            'if grep -q "^APP_URL=" "$env"; then',
            '  sed -i '.escapeshellarg('s|^APP_URL=.*|APP_URL='.$application->url().'|').' "$env"',
            'fi',
            // Not `artisan key:generate`: artisan needs `vendor/`, which the deploy script (a later
            // step) creates. 32 random base64 bytes is what key:generate writes.
            'if [ "$env" = "$root/.env" ] && ! grep -q "^APP_KEY=base64:." "$env"; then',
            '  if key=$(openssl rand -base64 32 2>/dev/null); then',
            // `|` as the delimiter: base64 can carry `/` but never `|` or `&`.
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
        // `set -e` first, before the `cd`: a failed `composer install` must not be followed by `migrate`.
        $body = $script ?? (string) $this->script($application);

        // Before anything runs: a `{PHP81}` with no PHP 8.1 behind it would reach the shell as text.
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

        // `fromResult` classifies the failure (out of memory, missing compiler) for composer/npm steps.
        if ($result->failed()) {
            throw ProvisioningFailedException::fromResult('script', $result);
        }

        $this->progress->record('script');
    }

    /**
     * A composer project must have its dependencies on disk before anything serves it.
     *
     * Otherwise the failure is silent: checkout and restarts succeed, then every request fatals on
     * the missing `vendor/autoload.php`. Gated on the manifest requiring something (a repo with only
     * `require-dev` serves fine), and platform entries (`php`, `ext-*`) do not count.
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
     * The install + build to run for a Node application nobody wrote a script for, or null.
     *
     * Only for Node-served applications whose `package.json` lists dependencies not on disk. The
     * package manager is the one on record, else the one whose lockfile exists; `npm ci` needs a
     * lockfile, so none means `npm install`.
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
     * Malformed JSON answers no: an unparseable manifest is not evidence anything is missing.
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

            // `ext-`/`lib-` are the interpreter's own; `composer-*-api` is satisfied by composer itself.
            if (str_starts_with($name, 'ext-') || str_starts_with($name, 'lib-') || str_starts_with($name, 'composer-')) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * What to run after checkout: `deploy_script`, else the legacy `build_command`, kept so older
     * applications deploy exactly as before.
     */
    public function script(Application $application): ?string
    {
        return filled($application->deploy_script)
            ? $application->deploy_script
            : $application->build_command;
    }

    /** Substitute the placeholders the script may use. */
    private function expand(string $script, Application $application, string $documentRoot): string
    {
        return strtr($script, $this->variables($application, $documentRoot));
    }

    /**
     * What each placeholder becomes in this site's deploy, as the Deployment screen shows it.
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
            // The site's own interpreter, without hardcoding a path that changes with its version.
            '{php}' => $this->phpBinary($application),
            '{path}' => $documentRoot,
            '{branch}' => $application->branch ?: 'main',
            '{domain}' => (string) $application->domain,
        ] + app(DeployScriptPhp::class)->aliases();
    }

    /**
     * The commit's subject and author, read from the checkout (what is on disk), not the webhook.
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
     * `export PATH=…;` putting the site's own Node first, or nothing when no version is pinned.
     *
     * Written into the shell command, not the environment: how much of that survives `runuser`
     * depends on its configuration.
     */
    private function nodePath(Application $application): string
    {
        if (blank($application->node_version)) {
            return '';
        }

        $bin = dirname($this->node->binaryPath((string) $application->node_version));

        return 'export PATH='.escapeshellarg($bin).':"$PATH"; ';
    }

    /** The site's PHP binary, or the bare name when it cannot be resolved. */
    private function phpBinary(Application $application): string
    {
        $version = (string) $application->php_version;

        if (preg_match('/^\d+\.\d+$/', $version) !== 1) {
            return 'php';
        }

        return $this->php->binaryPath($version) ?: 'php';
    }

    /**
     * `export PATH=…;` putting the site's own PHP first, or nothing when it cannot be established.
     *
     * Composer resolves platform requirements against the running PHP, so the wrong one makes every
     * request fail with a 500 after a green deploy (see {@see nodePath()}). A directory holding one
     * `php` symlink, not `/usr/bin`: apt installs every PHP there under a versioned name, and only
     * a `php` link satisfies a `#!/usr/bin/env php` shebang.
     */
    private function phpPath(Application $application): string
    {
        return $this->shim->exportFor($application);
    }

    /**
     * Hand a root-made `.git` back to the site user before git runs as them: older checkouts may
     * hold root-owned objects that would fail `fetch`. `-h`: a symlink is changed itself, never
     * what it points at.
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
     * Failures are logged, not thrown: the deploy itself succeeded.
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

    /** Rewrite the vhost and reload, refusing a config the web server rejects. */
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

        // A deploy whose new config never went live did not finish.
        $reload = $driver->reload();

        if ($reload->failed()) {
            throw new ProvisioningFailedException('reload', $reload->reference);
        }
    }
}
