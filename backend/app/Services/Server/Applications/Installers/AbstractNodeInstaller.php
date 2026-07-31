<?php

namespace App\Services\Server\Applications\Installers;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Server\Applications\ProvisionProgress;
use App\Services\Server\Runtimes\NodeRuntime;
use App\Services\Server\ServerOps;

/**
 * Shared machinery for the one-click Node applications.
 *
 * Three things differ from the PHP family, and each of them is a way to get
 * this wrong:
 *
 *  - **The site's own Node has to reach every command.** `npm` is a script
 *    with a `#!/usr/bin/env node` shebang, so running it finds whatever `node`
 *    the panel's PATH happens to have — not the version the site pinned and
 *    not the version its unit will run under. Every command here is prefixed
 *    with an explicit PATH instead.
 *  - **The application writes inside its own directory, not the home.** The
 *    unit sets `ProtectHome=read-only` with the document root as the single
 *    writable path, so an application that defaults to `~/.n8n` or
 *    `~/.node-red` must be pointed at the site instead — otherwise it starts,
 *    fails to write, and the reason is buried in the journal.
 *  - **They need no database.** These store their own state in a file beside
 *    the application. NodeBB is the exception and says so.
 */
abstract class AbstractNodeInstaller extends AbstractSiteInstaller
{
    public function __construct(
        ServerOps $serverOps,
        ProvisionProgress $progress,
        protected NodeRuntime $node,
    ) {
        parent::__construct($serverOps, $progress);
    }

    public function needsDatabase(): bool
    {
        return false;
    }

    /**
     * The command that starts this application once installed.
     *
     * The panel writes it rather than asking, because a one-click application
     * has exactly one right answer and the user has no way to know it. It is
     * stored on the application, so the process controls and the deploy path
     * treat these no differently from an app someone typed a command for.
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
     * Run a command as the site user with the site's Node first on PATH.
     *
     * `env` rather than a shell: these commands are the panel's own, fixed and
     * argument-shaped, so there is nothing here that needs a shell to parse
     * and no reason to hand one a string.
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
    ): void {
        $dir = $this->nodeDir($application);
        $path = ($dir === null ? '' : $dir.':').'/usr/local/bin:/usr/bin:/bin';

        $prefix = ['env', "PATH={$path}", 'HOME='.$application->systemUser->home_path];

        foreach ($environment as $key => $value) {
            $prefix[] = "{$key}={$value}";
        }

        $this->runAsSiteUser($step, $application, array_merge($prefix, $command), null, $cwd);
    }

    /**
     * Run a command whose environment holds secrets.
     *
     * The variables go into a 0600 file owned by the site user and are sourced
     * by a shell, rather than being passed to `env` as arguments: anything on
     * a command line is in `/proc/<pid>/cmdline` for the whole time the
     * command runs, and a database password or an admin password does not
     * belong there. The file is removed whether the command succeeds or not.
     *
     * A shell is used here — unlike everywhere else in this class — because
     * sourcing a file is the thing being asked for. Nothing user-supplied
     * reaches the shell string: the path is the panel's own and the command is
     * fixed.
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
     * PHP emits `$2y$`, which `bcryptjs` accepts but the native `bcrypt`
     * binding rejects outright. `$2a$` is accepted by both and is the same
     * hash — the prefix records which historical 8-bit handling produced it,
     * not a different algorithm.
     */
    protected function bcrypt(string $password): string
    {
        $hash = password_hash($password, PASSWORD_BCRYPT);

        return str_starts_with($hash, '$2y$') ? '$2a$'.substr($hash, 4) : $hash;
    }

    /**
     * Clone a project at a fixed branch into the document root.
     *
     * The directory already exists and holds the placeholder the provisioner
     * wrote, and `git clone` refuses a non-empty target — so this clones into
     * a temporary path and moves the contents in, which is also what makes a
     * retry after a failure converge instead of half-cloning.
     *
     * @throws ProvisioningFailedException
     */
    protected function cloneInto(Application $application, string $repository, string $branch, string $documentRoot): void
    {
        $work = rtrim((string) config('server.installer_work_dir', sys_get_temp_dir()), '/')
            .'/node-'.$application->id;

        $this->run('download', ['rm', '-rf', $work], $application);
        $this->run('download', [
            'git', 'clone', '--depth', '1', '--branch', $branch, $repository, $work,
        ], $application);

        // The placeholder would otherwise sit in the web root of an
        // application that never serves files from it.
        $this->run('extract', ['rm', '-f', $documentRoot.'/index.html', $documentRoot.'/index.php'], $application);
        $this->run('extract', ['cp', '-rT', $work, $documentRoot], $application);
        $this->run('extract', ['rm', '-rf', $work], $application);

        $this->run('extract', [
            'chown', '-R',
            "{$application->systemUser->username}:{$application->systemUser->username}",
            $documentRoot,
        ], $application);
    }
}
