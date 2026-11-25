<?php

namespace App\Services\Server\Applications\Installers;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Server\Applications\ApplicationConfigMutator;
use App\Services\Server\Applications\ProcessSupervisor;
use App\Services\Server\Applications\ProvisionProgress;
use App\Services\Server\Php\PhpShim;
use App\Services\Server\Php\RuntimeOwnership;
use App\Services\Server\Runtimes\NodeRuntime;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Str;

/**
 * Shared machinery for the one-click Node applications.
 *
 * Differences from the PHP family:
 *
 *  - The site's own Node must reach every command. `npm` has a `#!/usr/bin/env node` shebang
 *    and would find the panel's `node`, so every command gets an explicit PATH.
 *  - The application writes inside its document root, not the home: the unit sets
 *    `ProtectHome=read-only` with the document root as the only writable path, so apps that
 *    default to `~/.n8n` or `~/.node-red` must be pointed at the site.
 *  - They need no database (state lives in a file beside the app). NodeBB is the exception.
 */
abstract class AbstractNodeInstaller extends AbstractSiteInstaller
{
    public function __construct(
        ServerOps $serverOps,
        ProvisionProgress $progress,
        protected NodeRuntime $node,
        ApplicationConfigMutator $configMutator,
        ProcessSupervisor $supervisor,
        RuntimeOwnership $ownership,
        PhpShim $shim,
    ) {
        parent::__construct($serverOps, $progress, $configMutator, $supervisor, $ownership, $shim);
    }

    public function needsDatabase(): bool
    {
        return false;
    }

    /**
     * The command that starts this application once installed.
     *
     * Stored on the application, so process controls and deploys treat it like any user-typed
     * command.
     */
    abstract public function startCommand(Application $application, string $documentRoot): ?string;

    /**
     * Where the site's Node lives, or null when it pinned no version.
     */
    protected function nodeDir(Application $application): ?string
    {
        if (blank($application->node_version)) {
            return null;
        }

        return dirname($this->node->binaryPath((string) $application->node_version));
    }

    protected function nodeBinary(Application $application): string
    {
        $dir = $this->nodeDir($application);

        return $dir === null ? (string) config('server.node_binary', 'node') : $dir.'/node';
    }

    /**
     * npm 12 silently blocks dependency install scripts unless package.json lists them under
     * `allowScripts`, so native modules never get built (n8n crashes at start with "SQLite
     * package has not been found").
     *
     * These are the panel's own installs of packages it chose, so this restores npm 11's
     * behaviour of running scripts. npm 11 ignores the variable. A user's own git deploy does
     * not go through here.
     */
    private const NPM_ENVIRONMENT = ['npm_config_dangerously_allow_all_scripts' => 'true'];

    /**
     * Run a command as the site user with the site's Node first on PATH.
     *
     * `env` rather than a shell: the commands are fixed and argument-shaped.
     *
     * @param  array<int, string>  $command
     * @param  array<string, string>  $environment
     *
     * @throws ProvisioningFailedException
     */
    protected function runWithNode(
        string $step,
        Application $application,
        array $command,
        string $cwd,
        array $environment = [],
        ?string $input = null,
    ): ServerOpsResult {
        $dir = $this->nodeDir($application);
        $path = ($dir === null ? '' : $dir.':').'/usr/local/bin:/usr/bin:/bin';

        $prefix = ['env', "PATH={$path}", 'HOME='.$application->systemUser->home_path];

        foreach (self::NPM_ENVIRONMENT as $key => $value) {
            $prefix[] = "{$key}={$value}";
        }

        foreach ($environment as $key => $value) {
            $prefix[] = "{$key}={$value}";
        }

        return $this->runAsSiteUser($step, $application, array_merge($prefix, $command), $input, $cwd);
    }

    /**
     * Run a command whose environment holds secrets.
     *
     * The variables go into a 0600 file owned by the site user and are sourced by a shell, not
     * passed to `env` as arguments: a command line is visible in `/proc/<pid>/cmdline`, which
     * must not hold passwords. The file is removed whether the command succeeds or not.
     *
     * A shell is needed to source the file. Nothing user-supplied reaches the shell string: the
     * path is the panel's own and the command is fixed.
     *
     * @param  array<int, string>  $command
     * @param  array<string, string>  $secrets
     *
     * @throws ProvisioningFailedException
     */
    protected function runWithSecretEnv(
        string $step,
        Application $application,
        array $command,
        string $cwd,
        array $secrets,
    ): void {
        $dir = $this->nodeDir($application);
        $path = ($dir === null ? '' : $dir.':').'/usr/local/bin:/usr/bin:/bin';

        $file = rtrim((string) config('server.installer_work_dir', sys_get_temp_dir()), '/')
            .'/setup-'.$application->id.'.env';

        $contents = '';

        foreach ($secrets as $key => $value) {
            $contents .= $key.'='.escapeshellarg((string) $value)."\n";
        }

        $this->writeSecretFile($application, $file, $contents, '0600');

        $script = 'set -a; . '.escapeshellarg($file).'; set +a; '
            .'export PATH='.escapeshellarg($path).':"$PATH"; '
            .collect(self::NPM_ENVIRONMENT)->map(fn ($value, $key) => 'export '.$key.'='.escapeshellarg($value).'; ')->implode('')
            .'cd '.escapeshellarg($cwd).'; exec '
            .implode(' ', array_map(escapeshellarg(...), $command));

        try {
            $this->runAsSiteUser($step, $application, ['sh', '-c', $script]);
        } finally {
            $this->serverOps->run(
                ['rm', '-f', $file],
                ['feature' => 'application', 'op' => 'installer.cleanup', 'application' => $application->id],
            );
        }
    }

    /**
     * A bcrypt hash the Node ecosystem will accept.
     *
     * PHP emits `$2y$`, which the native `bcrypt` binding rejects; `$2a$` is accepted by both
     * and is the same hash.
     */
    protected function bcrypt(string $password): string
    {
        $hash = password_hash($password, PASSWORD_BCRYPT);

        return str_starts_with($hash, '$2y$') ? '$2a$'.substr($hash, 4) : $hash;
    }

    /**
     * Clone a project at a fixed branch into the document root.
     *
     * `git clone` refuses the non-empty document root (it holds the provisioner's placeholder),
     * so this clones into a temporary path and copies in, which also makes a retry converge.
     *
     * @throws ProvisioningFailedException
     */
    protected function cloneInto(Application $application, string $repository, string $branch, string $documentRoot): void
    {
        // An unguessable name, as downloadAndExtract() uses: a predictable one could be
        // created first by a site user.
        $work = rtrim((string) config('server.installer_work_dir', sys_get_temp_dir()), '/')
            .'/node-'.Str::uuid();

        $this->run('download', ['rm', '-rf', $work], $application);
        $this->run('download', [
            'git', 'clone', '--depth', '1', '--branch', $branch, $repository, $work,
        ], $application);

        // Drop the placeholder: this app never serves files from the web root.
        $this->run('extract', ['rm', '-f', $documentRoot.'/index.html', $documentRoot.'/index.php'], $application);
        $this->copyIntoSite($application, $work, $documentRoot, $work);
        $this->run('extract', ['rm', '-rf', $work], $application);

        $this->run('extract', [
            'chown', '-R',
            "{$application->systemUser->username}:{$application->systemUser->username}",
            $documentRoot,
        ], $application);
    }
}
