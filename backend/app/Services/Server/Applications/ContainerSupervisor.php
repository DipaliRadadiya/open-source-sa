<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\View;

/**
 * Runs an application that is a container.
 *
 * The counterpart to {@see ProcessSupervisor}, which does the same job for a
 * Node application and whose shape this deliberately follows: write the file
 * the supervisor reads, bring it up, and then **verify it actually came up**.
 *
 * That last part is the reason this is not three lines. `docker compose up -d`
 * exits 0 for a container that starts and immediately dies — a bad image, a
 * missing entrypoint, a command that returns — which is precisely what a wrong
 * configuration does. Without the check the panel reports a running
 * application that is in a restart loop, and the user is left looking at a
 * healthy green row and a 502.
 */
class ContainerSupervisor
{
    public function __construct(
        private ServerOps $serverOps,
        private ManagedFile $files,
        private ComposeValidator $validator,
    ) {}

    /**
     * The compose project name, which is also the container name prefix.
     *
     * Derived from the application id rather than its slug: a slug can be
     * renamed and a compose project cannot follow it — the old project would
     * be orphaned, still running, still holding the port.
     */
    public function project(Application $application): string
    {
        return 'sv-app-'.$application->id;
    }

    public function composePath(Application $application, string $documentRoot): string
    {
        return rtrim($documentRoot, '/').'/compose.yml';
    }

    /**
     * Write the compose file and bring the container up.
     *
     * @throws ProvisioningFailedException
     */
    public function apply(Application $application, string $documentRoot, bool $start = true): void
    {
        $path = $this->composePath($application, $documentRoot);

        $rendered = $this->contents($application, $documentRoot);

        $context = ['feature' => 'application', 'op' => 'compose_write', 'application' => $application->id];

        $written = $this->files->put($path, $rendered, $context);

        if ($written->failed()) {
            throw new ProvisioningFailedException('compose_write', $written->reference);
        }

        if (! $start) {
            return;
        }

        $result = $this->compose($application, $documentRoot, ['up', '-d', '--remove-orphans'], 'compose_up');

        if ($result->failed()) {
            throw new ProvisioningFailedException('container_start', $result->reference);
        }

        // `up` succeeding is not the container running. Asked separately, and
        // this is the check the whole class exists for.
        if (! $this->running($application, $documentRoot)) {
            throw new ProvisioningFailedException('container_exited', $result->reference);
        }
    }

    /**
     * The file to write: the user's, or one built from the fields.
     *
     * A user-supplied file is **validated again here**, not only when it was
     * saved. The form is not the only way a row changes — a restore, an import
     * or a direct edit all reach this method — and a rule enforced once at the
     * boundary is a rule that holds until something else writes the row.
     *
     * @throws ProvisioningFailedException
     */
    private function contents(Application $application, string $documentRoot): string
    {
        $compose = (string) $application->compose;

        if (trim($compose) !== '') {
            $verdict = $this->validator->validate($compose, $documentRoot);

            if (! $verdict['ok']) {
                throw new ProvisioningFailedException('compose_invalid', '', implode(' ', $verdict['errors']));
            }

            // The port nginx proxies to comes out of the file, not out of the
            // allocator. With a generated compose the panel chooses the port
            // and writes it in; with a pasted one the user has already chosen,
            // and proxying to the allocated port instead points nginx at
            // nothing — the container listens where the file says.
            //
            // Recorded on the application so the vhost and the container
            // cannot disagree, which is the same rule the unit file and the
            // Environment screen had to learn about naming one `.env`.
            $port = $this->validator->publishedPort(
                $verdict['resolved'] ?? [],
                $application->container_port ? (int) $application->container_port : null,
            );

            if ($port === null) {
                throw new ProvisioningFailedException(
                    'compose_no_port',
                    '',
                    __('errors/application.compose_port_ambiguous'),
                );
            }

            if ($application->app_port !== $port) {
                $application->forceFill(['app_port' => $port])->save();
            }

            // The validator's file, not the user's. They differ when a port
            // was published to every address and the panel bound it to
            // loopback — the row keeps what was typed so the editor shows it
            // back unchanged, and the disk gets what actually runs. The same
            // split every vhost and unit file here already has.
            return $verdict['compose'];
        }

        return View::make('server.docker.compose', [
            'project' => $this->project($application),
            'image' => (string) $application->image,
            'appPort' => (int) $application->app_port,
            'containerPort' => (int) ($application->container_port ?: 80),
            'documentRoot' => rtrim($documentRoot, '/'),
            'envPath' => $application->envPath(),
            'memoryLimit' => (string) ($application->memory_limit
                ?: config('server.docker.default_memory_limit', '512m')),
            // Null means Docker's default bridge, which is what a site gets
            // when nobody chose otherwise. Only reached on this branch: a
            // pasted compose file returns above, because a file that names its
            // own networks must not have one appended to it.
            'network' => $application->docker_network ?: null,
            // The site's name on that network. Unique, unlike the service name
            // `app`, which every generated file uses and which therefore
            // resolves to an arbitrary one of them when two sites share a
            // network.
            'alias' => $application->slug,
        ])->render();
    }

    /**
     * Is anything actually up?
     *
     * `--status running` rather than counting lines of `ps`: a container that
     * exited is still listed by compose, so an unfiltered count reports a dead
     * application as a live one.
     */
    public function running(Application $application, string $documentRoot): bool
    {
        $result = $this->compose(
            $application,
            $documentRoot,
            ['ps', '--status', 'running', '--quiet'],
            'compose_ps',
        );

        return $result->answered && trim($result->output()) !== '';
    }

    public function start(Application $application, string $documentRoot): ServerOpsResult
    {
        return $this->compose($application, $documentRoot, ['up', '-d'], 'compose_up');
    }

    public function stop(Application $application, string $documentRoot): ServerOpsResult
    {
        return $this->compose($application, $documentRoot, ['stop'], 'compose_stop');
    }

    public function restart(Application $application, string $documentRoot): ServerOpsResult
    {
        return $this->compose($application, $documentRoot, ['restart'], 'compose_restart');
    }

    /**
     * Take it down and remove what it left behind — except the data.
     *
     * No `--volumes`. Removing an application must not silently destroy the
     * database inside it; a container's volumes outliving it is recoverable,
     * and the reverse is not.
     */
    public function remove(Application $application, string $documentRoot): void
    {
        $this->compose($application, $documentRoot, ['down', '--remove-orphans'], 'compose_down');
    }

    /**
     * Recent output, bounded.
     *
     * `--tail` always, because a container that has been up for a month has
     * more log than anything should read into memory at once.
     */
    public function logs(Application $application, string $documentRoot, int $lines = 200): string
    {
        $result = $this->compose(
            $application,
            $documentRoot,
            ['logs', '--tail', (string) $lines, '--no-color'],
            'compose_logs',
        );

        return $this->plain($result->output() !== '' ? $result->output() : $result->errorOutput());
    }

    /**
     * Strip the terminal escape sequences the application itself emits.
     *
     * `--no-color` above is not enough and it is easy to assume it is: that
     * flag controls **compose's own** colouring — the service-name prefix —
     * and has no say over what the process inside the container prints. Uptime
     * Kuma colours its own log lines, so the panel showed
     *
     *     ^[[36m2026-09-24T10:42:27Z^[[0m [^[[32mSERVER^[[0m] …
     *
     * as literal `[36m` text. A terminal renders those as colour; a log viewer
     * that prints them verbatim shows noise around every timestamp.
     *
     * Stripped rather than rendered as colour, because every other source in
     * this viewer is a plain file — nginx writes no escape codes — and one tab
     * that needs an ANSI renderer would be a second way of displaying a log.
     * The information in the colour here is also in the text: `SERVER`,
     * `INFO:` and the timestamp are all still words.
     *
     * The CSI pattern covers the colour and cursor sequences; OSC covers the
     * window-title ones some tools emit, which would otherwise swallow the
     * rest of a line.
     */
    private function plain(string $output): string
    {
        return (string) preg_replace(
            [
                // CSI: ESC [ … final-byte — colours, cursor moves, erases.
                '/\x1B\[[0-9;?]*[ -\/]*[@-~]/',
                // OSC: ESC ] … terminated by BEL or ST (ESC backslash).
                // Titles and hyperlinks. The ST alternative needs four
                // backslashes here: two to survive PHP's single-quoted
                // string, leaving two for the regex to read as one literal.
                '/\x1B\][^\x07]*(?:\x07|\x1B\\\\)/',
                // Two-character escapes left over — charset selection and
                // friends. Bounded to letters so a stray ESC before ordinary
                // text cannot eat the character after it.
                '/\x1B[()#][0-9A-Za-z]/',
            ],
            '',
            $output,
        );
    }

    /**
     * Every compose call goes through here.
     *
     * Run as root via the panel's sudoers grant rather than as the site user:
     * reaching the Docker socket requires membership of the `docker` group,
     * and that membership is equivalent to root on this host — a member can
     * bind mount `/` into a container and write anywhere. The site user must
     * not have it, so the panel elevates the specific command instead.
     *
     * `-f` with an explicit path and `-p` with an explicit project, never a
     * bare `docker compose` relying on the working directory. Compose infers
     * the project name from the directory it is run in, and two applications
     * whose directories share a basename would otherwise infer the same
     * project and tear down each other's containers.
     *
     * @param  array<int, string>  $arguments
     */
    private function compose(Application $application, string $documentRoot, array $arguments, string $op): ServerOpsResult
    {
        return $this->serverOps->run(
            array_merge([
                'docker', 'compose',
                '-f', $this->composePath($application, $documentRoot),
                '-p', $this->project($application),
            ], $arguments),
            ['feature' => 'application', 'op' => $op, 'application' => $application->id],
            timeout: (int) config('server.docker.command_timeout', 600),
        );
    }
}
