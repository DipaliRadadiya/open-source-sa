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
 * The counterpart of {@see ContainerSupervisor}
 * for something that is not a site. It deliberately shares that class's rules and
 * none of its assumptions: same explicit `-f` and `-p`, same loopback publishing,
 * same named external volume — but no document root, no system user, no vhost and
 * no port allocated to be proxied to, because a database is reached directly.
 *
 * **Why the compose file lives outside any site's tree.** A database has no system
 * user to own it, and the file holds its password in plain text. So it sits in a
 * root-owned directory created on demand — on demand because install.sh runs once
 * and the updater ships code, never directories, so a path invented here would
 * exist on new boxes and be missing on every existing one.
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
     * The volume is created through {@see DockerResources} rather than left to
     * Compose, for the reason the template declares it `external: true`: a volume
     * the panel made is one the Docker page can list, attribute to this database,
     * and refuse to delete while something is using it.
     *
     * @throws DatabaseContainerFailedException
     */
    public function apply(DockerDatabase $database): void
    {
        $context = ['feature' => 'docker', 'docker_database' => $database->id];

        $this->docker->createVolume($database->volume());

        $directory = $this->directory($database);

        // 0750 rather than 0700: root writes it and root reads it, and the panel
        // runs elevated for both. Not world-readable, because the file has the
        // password in it.
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

        // `up -d` returns as soon as the container is created, which for a database
        // is well before it answers. Measured on a real box: the create endpoint
        // replied 201 and a connection made immediately with the credentials it
        // had just handed over was refused with "is the server running on that
        // host" — Postgres was still initialising its data directory.
        //
        // So the call waits for the engine's own healthcheck. A database that has
        // not become healthy in two minutes is not slow, it is broken — a bad
        // volume permission, an OOM, an image that cannot initialise — and the
        // caller rolls it back rather than leaving connection details that connect
        // to nothing.
        if (! $this->waitUntilHealthy($database)) {
            throw new DatabaseContainerFailedException('not_ready', '');
        }
    }

    /**
     * Wait for the engine to report itself healthy.
     *
     * Every template defines a healthcheck that asks the ENGINE rather than the
     * port — `pg_isready`, `mysqladmin ping`, `redis-cli ping` — because the port
     * is open while the server is still replaying WAL and refusing connections.
     *
     * A container with no health status at all answers true immediately. That is
     * not a loophole: it means the image defines no healthcheck and the panel's
     * template did not add one, in which case there is nothing to wait for and
     * blocking for two minutes would be a hang with no diagnosis.
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

            // Only two statuses mean "not yet". Everything else — `healthy`,
            // `none` for an image with no healthcheck, an unanswered inspect, a
            // format Docker changes in some future release — means there is
            // nothing to wait for.
            //
            // Written the other way round first (wait unless healthy) and it hung:
            // an unreadable status is indistinguishable from "starting", so the
            // whole timeout elapsed on every call. Same rule as the site
            // crash-loop check, for the same reason — a check that cannot read the
            // box must not be the thing that fails the operation.
            //
            // `unhealthy` IS transitional here: Docker marks it after the first
            // failed probe, and a database initialising its data directory fails
            // several before it starts answering.
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
     * `--volumes` only when asked. The volume is declared `external: true`, so
     * Compose cannot remove it either way — the flag is passed for symmetry with
     * the site path and because a future non-external volume would need it. The
     * data is removed by the caller, through the same guarded path a site's
     * volume goes through.
     */
    public function remove(DockerDatabase $database): void
    {
        $this->compose($database, ['down', '--remove-orphans'], 'db_down');

        // The directory and the file in it, which hold the password.
        $this->serverOps->run(
            ['rm', '-rf', $this->directory($database)],
            ['feature' => 'docker', 'docker_database' => $database->id, 'op' => 'db_dir_remove'],
        );
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
            // Reachable: the catalog is config, so an operator can remove a
            // version a row still names. Said as a failure with a step rather
            // than a null the caller has to notice.
            throw new DatabaseContainerFailedException('unknown_engine', '');
        }

        return View::make((string) $engine['template'], [
            'project' => $database->project(),
            'image' => $image,
            'name' => $database->name,
            'port' => $database->port,
            'enginePort' => (int) $engine['port'],
            'dataPath' => (string) $engine['data_path'],
            'volume' => $database->volume(),
            'network' => $database->docker_network,
            'credentials' => [
                'username' => (string) $database->credential('username', ''),
                'password' => (string) $database->credential('password', ''),
                'root_password' => (string) $database->credential('root_password', ''),
                'database' => (string) $database->credential('database', ''),
            ],
            'memoryLimit' => (string) config('server.docker.default_db_memory_limit', '512m'),
        ])->render();
    }

    /**
     * Every compose call for a database goes through here.
     *
     * Run as root via the sudoers grant for the reason the site supervisor states:
     * reaching the Docker socket needs membership of the `docker` group, and that
     * membership is equivalent to root on this host.
     *
     * `-f` with an explicit path and `-p` with an explicit project, never a bare
     * `docker compose` relying on the working directory — two databases whose
     * directories shared a basename would otherwise infer the same project and
     * tear down each other's containers.
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
