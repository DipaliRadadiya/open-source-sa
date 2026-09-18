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
        private PhpRuntime $php,
        private ProcessSupervisor $supervisor,
        private ProvisionProgress $progress,
        private DeploymentRecorder $recorder,
        private PhpShim $shim,
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
            $details = $this->commitDetails($documentRoot);

            // Recorded here rather than only on success. Everything below this
            // line can fail — ownership, the deploy script, the restarts, the
            // verify — and all of it fails with this revision already checked
            // out. A failed deploy that cannot say which commit it was running
            // is missing the one fact somebody debugging it needs.
            $this->recorder->commit($commit, $details['message'], $details['author']);

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
                ...$details,
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

            if ($code >= 200 && $code < 300) {
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

        throw new ProvisioningFailedException(
            'verify',
            $result->reference,
            "curl {$url} returned HTTP {$code}",
        );
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
            $this->phpPath($application),
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

        $autoload = $this->serverOps->run(
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
        return strtr($script, [
            // The site's own interpreter, spelled out. `php` on PATH already
            // resolves to it, but a script written before that was true may
            // name a version explicitly, and this is the way to do so without
            // hardcoding a path that changes when the site's version does.
            '{php}' => $this->phpBinary($application),
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
