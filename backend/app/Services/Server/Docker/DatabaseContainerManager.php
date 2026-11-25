<?php

namespace App\Services\Server\Docker;

use App\Models\DockerDatabase;
use App\Services\Server\Applications\ContainerSupervisor;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\View;

/**
 * Brings a containerised database up, and takes it down.
 *
 * The counterpart of {@see ContainerSupervisor} for something that is not a site: same
 * explicit `-f` and `-p`, loopback publishing and named external volume, but no document
 * root, system user, vhost or proxied port (a database is reached directly).
 *
 * The compose file lives outside any site's tree, in a root-owned directory created on
 * demand (the updater ships code, never directories), because it holds the password in plain
 * text and a database has no system user to own it.
 */
class DatabaseContainerManager
{
    public function __construct(
        private ServerOps $serverOps,
        private ManagedFile $files,
        private DockerResources $docker,
    ) {}

    /** The directory this database's compose file lives in. */
    public function directory(DockerDatabase $database): string
    {
        return rtrim((string) config('server.docker_databases.directory'), '/').'/'.$database->project();
    }

    public function composePath(DockerDatabase $database): string
    {
        return $this->directory($database).'/compose.yml';
    }

    /**
     * Create the volume, write the file, bring it up.
     *
     * The volume is created through {@see DockerResources} rather than left to Compose (the
     * template declares it `external: true`) so the Docker page can list it, attribute it to
     * this database, and refuse to delete it while in use.
     *
     * @throws DatabaseContainerFailedException
     */
    public function apply(DockerDatabase $database): void
    {
        $context = ['feature' => 'docker', 'docker_database' => $database->id];

        $this->docker->createVolume($database->volume());

        $directory = $this->directory($database);

        // Not world-readable: the compose file has the password in it.
        $this->serverOps->run(
            ['mkdir', '-m', '0750', '-p', $directory],
            $context + ['op' => 'db_dir'],
        );

        $written = $this->files->put($this->composePath($database), $this->contents($database), $context + ['op' => 'db_compose_write']);

        if ($written->failed()) {
            throw new DatabaseContainerFailedException('compose_write', $written->reference);
        }

        $result = $this->compose($database, ['up', '-d', '--remove-orphans'], 'db_up');

        if ($result->failed()) {
            throw new DatabaseContainerFailedException('container_start', $result->reference);
        }

        // `up -d` returns once the container is created, well before the database answers, so
        // wait for the engine's own healthcheck. One not healthy in two minutes is broken, and
        // the caller rolls it back rather than leave connection details that connect to nothing.
        if (! $this->waitUntilHealthy($database)) {
            throw new DatabaseContainerFailedException('not_ready', '');
        }
    }

    /**
     * Wait for the engine to report itself healthy.
     *
     * Every template's healthcheck asks the ENGINE rather than the port (`pg_isready`,
     * `mysqladmin ping`, `redis-cli ping`), because the port is open while the server is still
     * replaying WAL and refusing connections.
     *
     * A container with no health status at all answers true at once: there is nothing to wait
     * for, and blocking for two minutes would be a hang with no diagnosis.
     */
    private function waitUntilHealthy(DockerDatabase $database): bool
    {
        $deadline = time() + (int) config('server.docker_databases.ready_timeout', 120);
        $container = $database->project().'-db-1';

        while (time() < $deadline) {
            $result = $this->serverOps->run(
                ['docker', 'inspect', '--format', '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}', $container],
                ['feature' => 'docker', 'op' => 'db_health', 'docker_database' => $database->id],
                timeout: 20,
            );

            $status = trim($result->output());

            // Only these two statuses mean "not yet"; anything else (`healthy`, `none`, an
            // unreadable inspect) means nothing to wait for. Do not invert this (wait unless
            // healthy): an unreadable status would then burn the whole timeout.
            // `unhealthy` is transitional: a database initialising fails several probes first.
            if (! in_array($status, ['starting', 'unhealthy'], true)) {
                return true;
            }

            sleep(3);
        }

        return false;
    }

    /**
     * Stop it and remove its container.
     *
     * The volume is `external: true`, so Compose never removes it; the caller removes the data
     * through the same guarded path a site's volume goes through.
     *
     * A failed `down` while the container is still there throws, keeping the directory and row
     * so a retry still has a compose file to stop it with.
     *
     * One repair is tried first: the compose file is rewritten from the row and `down` asked
     * again, since a missing or hand-edited file is the one cause the panel can fix itself.
     *
     * @throws DatabaseContainerFailedException
     */
    public function remove(DockerDatabase $database): void
    {
        $down = $this->compose($database, ['down', '--remove-orphans'], 'db_down');

        if ($down->failed() && $this->containerRemains($database)) {
            $down = $this->downFromFreshFile($database) ?? $down;

            if ($down->failed() && $this->containerRemains($database)) {
                throw new DatabaseContainerFailedException('db_down', $down->reference);
            }
        }

        // The directory and the file in it, which hold the password.
        $this->serverOps->run(
            ['rm', '-rf', $this->directory($database)],
            ['feature' => 'docker', 'docker_database' => $database->id, 'op' => 'db_dir_remove'],
        );
    }

    /**
     * Write the compose file again from the row, then `down` with it.
     *
     * Null when the file could not be rendered or written: the caller then
     * reports the first failure, which is the one that explains the problem.
     */
    private function downFromFreshFile(DockerDatabase $database): ?ServerOpsResult
    {
        $context = ['feature' => 'docker', 'docker_database' => $database->id];

        try {
            $contents = $this->contents($database);
        } catch (DatabaseContainerFailedException) {
            return null;
        }

        $this->serverOps->run(['mkdir', '-m', '0750', '-p', $this->directory($database)], $context + ['op' => 'db_dir']);

        if ($this->files->put($this->composePath($database), $contents, $context + ['op' => 'db_compose_write'])->failed()) {
            return null;
        }

        return $this->compose($database, ['down', '--remove-orphans'], 'db_down');
    }

    /**
     * Does any container of this database's project still exist, running or not?
     *
     * Asked after a failed `down`, which also fails when the container is already gone. Only
     * an answer of "none" counts as gone: Docker not answering is "still there".
     */
    private function containerRemains(DockerDatabase $database): bool
    {
        $result = $this->serverOps->run(
            ['docker', 'ps', '-a', '-q', '--filter', 'label=com.docker.compose.project='.$database->project()],
            ['feature' => 'docker', 'docker_database' => $database->id, 'op' => 'db_container_check'],
            timeout: 30,
        );

        return $result->failed() || trim($result->output()) !== '';
    }

    /** Is the container up right now? */
    public function running(DockerDatabase $database): bool
    {
        $result = $this->compose($database, ['ps', '--status', 'running', '--quiet'], 'db_ps');

        return $result->answered && trim($result->output()) !== '';
    }

    /**
     * The rendered compose file.
     *
     * @throws DatabaseContainerFailedException
     */
    public function contents(DockerDatabase $database): string
    {
        $engine = $database->engineConfig();
        $image = $database->image();

        if ($engine === null || $image === null) {
            // Reachable: the catalog is config, so a version a row still names can be removed.
            throw new DatabaseContainerFailedException('unknown_engine', '');
        }

        return View::make((string) $engine['template'], [
            'project' => $database->project(),
            'image' => $image,
            'name' => $database->name,
            'port' => $database->port,
            'enginePort' => (int) $engine['port'],
            // Asked of the ROW, not the engine: Postgres 18 keeps its data elsewhere.
            'dataPath' => $database->dataPath(),
            'volume' => $database->volume(),
            'network' => $database->docker_network,
            'credentials' => [
                'username' => (string) $database->credential('username', ''),
                'password' => (string) $database->credential('password', ''),
                'root_password' => (string) $database->credential('root_password', ''),
                'database' => (string) $database->credential('database', ''),
            ],
            // The instance's own size first, then the server default.
            'memoryLimit' => (string) ($database->memory_limit
                ?: config('server.docker.default_db_memory_limit', '512m')),
            // No fallback: null means no CPU quota, as for a site. A default would throttle
            // every existing database when it is next recreated.
            'cpuLimit' => $database->cpu_limit ?: null,
        ])->render();
    }

    /**
     * Every compose call for a database goes through here.
     *
     * Run as root via the sudoers grant (Docker socket access is equivalent to root here).
     *
     * Always an explicit `-f` and `-p`, never the working directory: databases whose
     * directories share a basename would otherwise infer the same project and tear down each
     * other's containers.
     *
     * @param  array<int, string>  $arguments
     */
    private function compose(DockerDatabase $database, array $arguments, string $op): ServerOpsResult
    {
        return $this->serverOps->run(
            array_merge(
                ['docker', 'compose', '-f', $this->composePath($database), '-p', $database->project()],
                $arguments,
            ),
            ['feature' => 'docker', 'op' => $op, 'docker_database' => $database->id],
            timeout: (int) config('server.docker.command_timeout', 600),
        );
    }
}
