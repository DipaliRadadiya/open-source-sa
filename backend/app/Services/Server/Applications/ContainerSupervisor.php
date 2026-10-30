<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Recipes\RecipeRegistry;
use App\Services\Server\Docker\RegistryAuth;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

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
    /**
     * The ceiling for a question about containers that already exist —
     * `ps`, `logs` — as opposed to `up`/`pull`, which may download gigabytes
     * and get `server.docker.command_timeout`. The readiness check asks these
     * on every try and once more after its deadline, and a `compose ps` that
     * has not answered in half a minute is not going to (DS-12).
     */
    public const QUERY_TIMEOUT = 30;

    public function __construct(
        private ServerOps $serverOps,
        private ManagedFile $files,
        private ComposeValidator $validator,
        private RegistryAuth $registryAuth,
        // Optional so a supervisor built by hand still works; it then checks
        // through its own ServerOps, never a second one from the container.
        private ?ContainerReadinessCheck $readiness = null,
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
    /**
     * The services of the last pasted compose file this instance rendered, keyed
     * by name, each with its resolved definition.
     *
     * Set by `contents()` and read by `writeOverride()`, because the override has
     * to name each service and only the resolved document knows what they are —
     * and what limits each one already sets, which decides the key the override
     * may use without Compose refusing the pair (RC-42).
     * Empty for a generated file, whose template carries its own limits.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $pastedServices = [];

    /**
     * `$install` marks the first install, from the provisioning job: its `up`
     * pulls every image for the first time and gets
     * `server.docker.install_timeout` rather than `command_timeout` (RC-20).
     * Every other caller runs inside a request or a shorter job and keeps 600 s.
     */
    public function apply(Application $application, string $documentRoot, bool $start = true, bool $install = false): void
    {
        $path = $this->composePath($application, $documentRoot);

        $rendered = $this->contents($application, $documentRoot);

        $context = ['feature' => 'application', 'op' => 'compose_write', 'application' => $application->id];

        $written = $this->files->put($path, $rendered, $context);

        if ($written->failed()) {
            throw new ProvisioningFailedException('compose_write', $written->reference);
        }

        $override = $this->writeOverride($application, $documentRoot, $context);

        if (! $start) {
            return;
        }

        // The pull happens inside `up`, so the credential has to be available
        // for it — and only for it. `with()` writes the file, runs this, and
        // removes it in a `finally`, including when the pull is what failed.
        $timeout = $install ? (int) config('server.docker.install_timeout', 1200) : null;

        $result = $this->registryAuth->with(
            $application->registry,
            ['feature' => 'application', 'application' => $application->id],
            function (?string $auth) use ($application, $documentRoot, $override, $timeout): ServerOpsResult {
                $repair = $this->recreateDetached($application, $documentRoot, $override, $auth, $timeout);

                if ($repair !== null && $repair->failed()) {
                    return $repair;
                }

                return $this->compose(
                    $application,
                    $documentRoot,
                    ['up', '-d', '--remove-orphans'],
                    'compose_up',
                    $override,
                    $auth,
                    $timeout,
                );
            },
        );

        if ($result->failed()) {
            // `fromResult` rather than the bare constructor, so the one failure
            // this step has that is not self-explanatory gets named: a registry
            // that refused the pull. Everything else still classifies to null and
            // is answered by the reference, which is the rule that class states.
            throw ProvisioningFailedException::fromResult('container_start', $result);
        }

        // A site the user described — an image, or a pasted file — waits until
        // it answers on its port (DS-03), and a failure is stored on the site
        // with the reason and the container's log. One-click apps keep the
        // checks below: their installers wait for first boot themselves.
        if (! $this->panelRendered($application) && (int) $application->app_port > 0) {
            $this->readiness()->verify($application, $documentRoot, $this);

            return;
        }

        // `up` succeeding is not the container running. Asked separately, and
        // this is the check the whole class exists for.
        if (! $this->running($application, $documentRoot)) {
            throw new ProvisioningFailedException('container_exited', $result->reference);
        }

        // And "running" at one instant is not running, either.
        //
        // A container that starts, exits and is restarted by its policy spends part
        // of every cycle in the `running` state, so a single sample of `compose ps
        // --status running` can catch it mid-bounce and call the site healthy. The
        // site then answers 502 while the panel reports it active — measured with a
        // real image (`curlimages/curl`, which prints usage and exits): the panel
        // said `active` and the container was on its tenth restart.
        if ($this->crashLooping($application, $documentRoot)) {
            throw new ProvisioningFailedException('container_restarting', $result->reference, 'container_restarting');
        }

        $this->recordUp($application);
    }

    /**
     * A container that passed the checks above — a one-click app, whose
     * installer does its own first-boot waiting after this — reports
     * `running`, the word an image site's readiness check stores (DS-14). It
     * was left null, so the API said nothing while the panel page said
     * Running.
     */
    private function recordUp(Application $application): void
    {
        $application->forceFill([
            'container_status' => 'running',
            'last_failure' => null,
        ])->save();
    }

    private function readiness(): ContainerReadinessCheck
    {
        return $this->readiness ??= new ContainerReadinessCheck($this->serverOps);
    }

    /**
     * Rebuild the services whose container is attached to no network at all.
     *
     * A port clash makes `compose up` fail while Docker is wiring the
     * container's network, and Docker leaves that container attached to
     * NOTHING. The next `up` sees a container whose configuration has not
     * changed and simply starts it again: it publishes no port and cannot
     * reach its database, so a one-click app crash-loops on "Invalid database
     * host" while its database, still running, made the resume answer 200.
     * Found in Docker QA on a real box (Ghost after a port clash); Pull and
     * redeploy refuses a stopped container, so there was no way out from the
     * panel at all.
     *
     * Zero networks is proof, not a guess: the panel refuses `network_mode` in
     * every compose file it runs, so each of its containers is on at least one
     * network. Only those services are recreated (`--no-deps`), and a recreate
     * keeps named, bind and anonymous volumes. Proven live before this was
     * written: the database container kept its id and a marker in it and in
     * the app's volume survived. A healthy project has nothing to repair and
     * costs one listing and one inspect.
     *
     * Null when there was nothing to repair or Docker could not be asked —
     * unreadable output is no evidence, the same rule as {@see crashLooping()}.
     */
    private function recreateDetached(Application $application, string $documentRoot, ?string $override, ?string $auth, ?int $timeout): ?ServerOpsResult
    {
        $services = $this->detachedServices($application);

        if ($services === []) {
            return null;
        }

        return $this->compose(
            $application,
            $documentRoot,
            ['up', '-d', '--no-deps', '--force-recreate', ...$services],
            'compose_recreate_detached',
            $override,
            $auth,
            $timeout,
        );
    }

    /**
     * The compose services of this project whose container is on no network.
     *
     * @return list<string>
     */
    private function detachedServices(Application $application): array
    {
        $ids = $this->serverOps->run(
            ['docker', 'ps', '-aq', '--filter', 'label=com.docker.compose.project='.$this->project($application)],
            ['feature' => 'application', 'op' => 'container_ids', 'application' => $application->id],
            timeout: self::QUERY_TIMEOUT,
        );

        $list = $ids->answered ? (preg_split('/\s+/', trim($ids->output())) ?: []) : [];
        $list = array_values(array_filter($list, fn (string $id): bool => preg_match('/^[0-9a-f]{6,64}$/', $id) === 1));

        if ($list === []) {
            return [];
        }

        $inspect = $this->serverOps->run(
            ['docker', 'inspect', '--format', '{{index .Config.Labels "com.docker.compose.service"}} {{len .NetworkSettings.Networks}}', ...$list],
            ['feature' => 'application', 'op' => 'container_networks', 'application' => $application->id],
            timeout: self::QUERY_TIMEOUT,
        );

        if (! $inspect->answered) {
            return [];
        }

        $detached = [];

        foreach (preg_split('/\r?\n/', trim($inspect->output())) ?: [] as $line) {
            if (preg_match('/^([A-Za-z0-9][A-Za-z0-9_.-]*) (\d+)$/', trim($line), $m) === 1 && (int) $m[2] === 0) {
                $detached[] = $m[1];
            }
        }

        return array_values(array_unique($detached));
    }

    /**
     * Is any of this project's containers bouncing rather than staying up?
     *
     * Two signals, because one alone is either blind or wrong:
     *
     *  - **State `restarting`.** Direct, and what Docker calls a container between
     *    a crash and its next attempt. Docker's backoff grows, so a loop spends an
     *    increasing share of its time here — but early in a loop the windows are
     *    short, and a single sample can miss them.
     *  - **A restart count above zero on a container that has just started.** The
     *    timing qualifier is what makes this safe: `apply()` also runs on a site
     *    that has been up for weeks, and a container that crashed once last month
     *    has a non-zero count forever. Measured on a real box, a host reboot does
     *    NOT inflate it — containers that came back after one read zero — so a
     *    fresh container with restarts on the clock has restarted for its own
     *    reasons.
     *
     * **The second signal is a suspicion, not a verdict.** One restart is not a
     * loop: a process that died once and came straight back is the restart policy
     * doing its job. Answering "looping" for that refused to resume a site that was
     * up and serving — found in QA on a real box (F2): PID 1 killed once, Docker
     * restarted it, and every resume for the next two minutes answered 500 and
     * left the site paused. So a suspect is looked at again after
     * `server.docker.crash_loop_confirm_seconds`, and it is a loop only if it
     * restarted again in between or is no longer running. A real loop cannot hide
     * from that: Docker holds a crashed container in `restarting` for the whole
     * backoff delay, and the delay is short while the count climbs, so ten seconds
     * later it is either restarting or has a higher count. The wait is paid only
     * when something looked wrong.
     *
     * Unreadable output answers false. This runs after a successful `up`, and
     * failing a deploy because a status query did not parse would turn a working
     * site into a reported failure — the opposite of the mistake it exists to stop.
     */
    public function crashLooping(Application $application, string $documentRoot): bool
    {
        $result = $this->compose($application, $documentRoot, ['ps', '--format', 'json'], 'compose_ps_state', timeout: self::QUERY_TIMEOUT);

        if (! $result->answered) {
            return false;
        }

        $suspects = [];

        foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $row = json_decode($line, true);

            if (! is_array($row)) {
                continue;
            }

            if (strtolower((string) ($row['State'] ?? '')) === 'restarting') {
                return true;
            }

            // `compose ps --format json` does not carry a restart count, so the
            // per-container question is asked of Docker directly and only for the
            // containers this project actually has.
            $name = (string) ($row['Name'] ?? '');
            $state = $name === '' ? null : $this->restartState($name);

            if ($state !== null && $this->restartedRecently($state)) {
                $suspects[$name] = $state['count'];
            }
        }

        if ($suspects === []) {
            return false;
        }

        Sleep::for((int) config('server.docker.crash_loop_confirm_seconds', 10))->seconds();

        foreach ($suspects as $name => $count) {
            $again = $this->restartState($name);

            // Unreadable the second time is unreadable, not a loop — the same
            // rule as the first sample.
            if ($again === null) {
                continue;
            }

            if ($again['count'] > $count || ! in_array($again['status'], ['running', null], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the image's own HEALTHCHECK says, across this project (DS-14).
     *
     * `unhealthy` if any container is, then `starting` if any still is, then
     * `healthy` — or null when no container has a healthcheck at all, or the
     * question could not be answered. Null is "Docker has no opinion", never
     * "fine": the caller falls back to asking the port.
     *
     * The image's author knows what "ready" means for it far better than a
     * GET of `/` does. Kanboard answered `/` with a 200 error page while its
     * own check had already called it unhealthy, and the panel said Running.
     */
    public function health(Application $application, string $documentRoot): ?string
    {
        $seen = [];

        foreach ($this->psRows($application, $documentRoot) as $row) {
            $health = strtolower((string) ($row['Health'] ?? ''));

            if (in_array($health, ['healthy', 'starting', 'unhealthy'], true)) {
                $seen[$health] = true;
            }
        }

        foreach (['unhealthy', 'starting', 'healthy'] as $health) {
            if (isset($seen[$health])) {
                return $health;
            }
        }

        return null;
    }

    /**
     * The last thing a failing healthcheck printed, from the first unhealthy
     * container — the reason in the image's own words. Empty when unreadable:
     * a diagnostic must never be the thing that fails.
     */
    public function healthOutput(Application $application, string $documentRoot): string
    {
        foreach ($this->psRows($application, $documentRoot) as $row) {
            $name = (string) ($row['Name'] ?? '');

            if (strtolower((string) ($row['Health'] ?? '')) !== 'unhealthy' || $name === '') {
                continue;
            }

            $inspect = $this->serverOps->run(
                ['docker', 'inspect', '--format', '{{json .State.Health.Log}}', $name],
                ['feature' => 'application', 'op' => 'container_health'],
                timeout: 20,
            );

            $log = json_decode(trim($inspect->output()), true);
            $last = is_array($log) && $log !== [] ? end($log) : null;

            return is_array($last) ? trim((string) ($last['Output'] ?? '')) : '';
        }

        return '';
    }

    /**
     * `compose ps --format json`, one decoded row per container. Compose
     * prints one object per line; older releases printed one array.
     *
     * @return list<array<string, mixed>>
     */
    private function psRows(Application $application, string $documentRoot): array
    {
        $result = $this->compose($application, $documentRoot, ['ps', '--format', 'json'], 'compose_ps_health', timeout: self::QUERY_TIMEOUT);

        if (! $result->answered) {
            return [];
        }

        $rows = [];

        foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $line) {
            $decoded = json_decode(trim($line), true);

            if (! is_array($decoded)) {
                continue;
            }

            foreach (array_is_list($decoded) ? $decoded : [$decoded] as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * How often this container has restarted, when it last started, and what
     * state it is in now — or null when Docker did not answer.
     *
     * One call, because two calls to inspect the same container is two chances
     * for the answer to change underneath. The status is last so that a missing
     * one leaves the other two readable; it is null then, which the caller reads
     * as "no evidence either way".
     *
     * @return array{count: int, started_at: int|false, status: ?string}|null
     */
    private function restartState(string $name): ?array
    {
        $result = $this->serverOps->run(
            ['docker', 'inspect', '--format', '{{.RestartCount}} {{.State.StartedAt}} {{.State.Status}}', $name],
            ['feature' => 'application', 'op' => 'container_restarts'],
            timeout: 20,
        );

        if (! $result->answered) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($result->output()));

        // A container that is gone, or output that is not Docker's, says nothing
        // about restarts. Reading it as "count 0" would be a guess that happens to
        // be harmless here; null says what it is.
        if (! ctype_digit($parts[0] ?? '')) {
            return null;
        }

        return [
            'count' => (int) ($parts[0] ?? 0),
            'started_at' => strtotime($parts[1] ?? ''),
            'status' => isset($parts[2]) && $parts[2] !== '' ? strtolower($parts[2]) : null,
        ];
    }

    /**
     * Restarted at least once, and started within the last two minutes. Only
     * a container that started recently: an older one's restarts are history,
     * not a loop — see the note on {@see crashLooping()}.
     *
     * @param  array{count: int, started_at: int|false, status: ?string}  $state
     */
    private function restartedRecently(array $state): bool
    {
        return $state['count'] >= 1
            && $state['started_at'] !== false
            && (time() - $state['started_at']) < 120;
    }

    /**
     * Fetch a newer image and recreate the container on it.
     *
     * The update story for a container, and it has to be its own action because
     * **`up` alone does not do it.** Compose reuses an image it already has
     * locally, so a site on a floating tag stays on the layers it first pulled
     * for as long as the tag exists — which reads as "the update button does
     * nothing". `pull` is the part that talks to the registry.
     *
     * Two commands, one credential window: the token is written once and both
     * run inside it, rather than being written, removed, and written again.
     *
     * `up` still runs even if nothing new was pulled. That is not wasted work —
     * it is what recreates the container onto an image that DID change, and
     * compose already no-ops when neither the image nor the file moved.
     *
     * @throws ProvisioningFailedException
     */
    public function pull(Application $application, string $documentRoot): void
    {
        // Re-render first, exactly as `start()` does. `writeOverride()` reads
        // `$pastedServices`, which only `contents()` populates — called without it
        // the list is empty, the override is DELETED, and `up` recreates the
        // container with no `mem_limit`, no `cpus` and unbounded logs. So pressing
        // "Pull and redeploy" on a pasted-compose site silently removed its limits.
        $this->contents($application, $documentRoot);

        $override = $this->writeOverride(
            $application,
            $documentRoot,
            ['feature' => 'application', 'op' => 'compose_override', 'application' => $application->id],
        );

        $context = ['feature' => 'application', 'application' => $application->id];

        $this->registryAuth->with($application->registry, $context, function (?string $auth) use ($application, $documentRoot, $override): void {
            $pulled = $this->compose($application, $documentRoot, ['pull'], 'compose_pull', null, $auth);

            if ($pulled->failed()) {
                throw ProvisioningFailedException::fromResult('image_pull', $pulled);
            }

            $started = $this->compose($application, $documentRoot, ['up', '-d', '--remove-orphans'], 'compose_up', $override, $auth);

            if ($started->failed()) {
                throw ProvisioningFailedException::fromResult('container_start', $started);
            }
        });

        // A redeploy is where a fixed port or env is proved, so it gets the
        // same readiness check as the first deploy, and clears its failure.
        if (! $this->panelRendered($application) && (int) $application->app_port > 0) {
            $this->readiness()->verify($application, $documentRoot, $this);

            return;
        }

        // Asked after the credential window closes, because it needs no
        // credential and the window should be as short as the work requires.
        if (! $this->running($application, $documentRoot)) {
            throw new ProvisioningFailedException('container_exited', '');
        }

        $this->recordUp($application);
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
        // Reset per render, not only set on the pasted branch. `UpdateContainerCompose`
        // rolls back by calling this again on the SAME instance — pasted file first,
        // generated file second — and a list left over from the first names services
        // the second does not have, so the override `compose up` is given refers to
        // nothing and the rollback fails with the site already down.
        $this->pastedServices = [];

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
                // Held by another site: the forms refuse that file, but a row they
                // never saw reaches here too. Left to the unique index it was a
                // QueryException out of the job and a `failed` site with no
                // reason (RC-43). A reason code, titled in the viewer's language
                // like every other one, not a sentence in the job's.
                if (Application::query()->where('app_port', $port)->whereKeyNot($application->getKey())->exists()) {
                    throw new ProvisioningFailedException('compose_port_taken', '', 'compose_port_taken');
                }

                $application->forceFill(['app_port' => $port])->save();
            }

            // The service names the panel has to protect, taken from the
            // RESOLVED document rather than by reading the user's text: compose
            // accepts `extends`, anchors and profiles, and the resolved document
            // is the only place their result is visible.
            //
            // The definitions are kept as well as the names: a service that sets
            // its own limit has to be overridden under the same key (RC-42).
            $this->pastedServices = array_map(
                fn (mixed $definition): array => (array) $definition,
                (array) ($verdict['resolved']['services'] ?? []),
            );

            // The validator's file, not the user's. They differ when a port
            // was published to every address and the panel bound it to
            // loopback — the row keeps what was typed so the editor shows it
            // back unchanged, and the disk gets what actually runs. The same
            // split every vhost and unit file here already has.
            return $verdict['compose'];
        }

        return $this->generated($application, $documentRoot);
    }

    /**
     * The compose file the panel would write from this site's fields.
     *
     * Public and side-effect free, because the compose editor has to show a
     * simple-mode site its current file before anybody edits it — and a GET that
     * quietly rewrote `app_port` on the way past would be a read that changes the
     * server. That is what the pasted-file branch above does, deliberately, and it
     * is exactly why the two are separate methods now.
     */
    public function generated(Application $application, string $documentRoot): string
    {
        // No port, no file — never a default (DS-12). `?: 80` rendered a
        // site with no container port as one listening on 80, and nginx
        // proxied to a port nothing in the image listened on while the panel
        // said Running. Create and `PUT /container` both refuse a missing
        // port now; this is the weld behind them, for a row that arrives
        // without one by any other route.
        if ((int) $application->container_port <= 0) {
            throw new ProvisioningFailedException('compose_no_port', '', 'container_port_missing');
        }

        $values = [
            'project' => $this->project($application),
            'image' => (string) $application->image,
            'appPort' => (int) $application->app_port,
            'containerPort' => (int) $application->container_port,
            'documentRoot' => rtrim($documentRoot, '/'),
            'siteMount' => $application->siteMountPath(),
            'envPath' => $application->envPath(),
            'memoryLimit' => (string) ($application->memory_limit
                ?: config('server.docker.default_memory_limit', '512m')),
            // No fallback, unlike memory on the line above, and the asymmetry is
            // the design rather than an omission: null here means no CPU quota at
            // all, so a site that has never had one renders this file exactly as
            // it did before the field existed. Giving it a default would cap every
            // existing container site on its next deploy.
            'cpuLimit' => $application->cpu_limit ?: null,
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
            // Named volumes, each with the path it mounts at inside the
            // container. A list, unlike the network: a site mounts as many as it
            // has data worth keeping.
            'mounts' => array_values(array_filter(
                (array) ($application->volume_mounts ?? []),
                fn ($mount): bool => is_array($mount)
                    && ($mount['volume'] ?? '') !== ''
                    && ($mount['path'] ?? '') !== '',
            )),
        ];

        $this->refuseLineBreaks($application, $values);

        return View::make('server.docker.compose', $values)->render();
    }

    /**
     * Refuse to render a value that would start a new line of the file.
     *
     * The template writes every value as a bare YAML scalar, and Blade's escaping
     * leaves newlines alone, so a value with a line break in it is not one value:
     * the text after the break is a new key — `privileged: true`, a bind of `/` —
     * in a file nothing validates after this point. Each field is validated where
     * it is accepted; this is the one check every field passes through, so a
     * field added later, or a row written by something other than a request,
     * cannot reopen it. Byte-for-byte the same output for every legitimate site.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws ProvisioningFailedException
     */
    private function refuseLineBreaks(Application $application, array $values): void
    {
        $scalars = [];

        array_walk_recursive($values, function (mixed $value, int|string $key) use (&$scalars): void {
            if (is_string($value)) {
                $scalars[] = [$key, $value];
            }
        });

        foreach ($scalars as [$key, $value]) {
            if (preg_match('/[\r\n]/', $value) === 1) {
                $reference = (string) Str::uuid();

                Log::channel('server-ops')->error('Refused to render a compose file: a value contains a line break', [
                    'reference' => $reference,
                    'feature' => 'application',
                    'op' => 'compose_render',
                    'application' => $application->id,
                    'field' => $key,
                ]);

                throw new ProvisioningFailedException('compose_write', $reference);
            }
        }
    }

    /**
     * Remove an override that is no longer wanted, and answer null.
     *
     * Not passed to `up` once this method is reached, so a leftover file is inert
     * — but it is a file on disk stating limits the container does not have, in the
     * site's own directory, where the File Manager shows it and anybody debugging
     * will read it. The one-click sites created before the guard above existed each
     * have one saying the database is capped at the app's ceiling, which is exactly
     * the wrong thing to find while looking for why a database is slow.
     *
     * Also covers a site that moves from a pasted file back to a generated one:
     * that path returned null and left the old override behind too.
     *
     * A failed delete is not a provisioning failure. The file is unreferenced
     * either way, and refusing to bring a site up because a stale file could not be
     * removed would trade a cosmetic problem for an outage.
     *
     * @param  array<string, mixed>  $context
     */
    private function discardOverride(string $documentRoot, array $context): ?string
    {
        $this->files->delete(rtrim($documentRoot, '/').'/compose.panel.yml', $context);

        return null;
    }

    /**
     * Did the panel write this site's `compose` column itself?
     *
     * True for every recipe app, because `DockerAppInstaller` renders that
     * recipe's template into the column — so the file is the panel's own work and
     * already carries everything the override exists to add.
     *
     * The recipe registry is the source of truth, including deprecated recipes
     * that must still resolve for existing sites.
     */
    public function panelRendered(Application $application): bool
    {
        return app(RecipeRegistry::class)->has((string) $application->site_type);
    }

    /**
     * Write the panel's own compose override, and return its path.
     *
     * **Why an override file rather than editing the user's YAML.** A pasted compose
     * file is the user's, shown back to them unchanged in the editor, and rewriting
     * it to insert keys means parsing and re-emitting YAML — which loses comments,
     * reorders keys and is a bug farm. Compose is built for this: a second `-f`
     * merges over the first, and `docker compose` itself does the merging.
     *
     * **What it protects, and why it is not optional.** `ComposeValidator` rewrites
     * published ports to loopback and refuses the keys that hand over the host, but
     * it adds nothing — measured, not assumed: zero references to `mem_limit` or
     * `logging` in that class. So a hand-written compose file was a site with **no
     * memory ceiling and unbounded logs**, which are precisely the two failures the
     * generated template carries a paragraph each about: one container exhausting
     * the box and taking the panel with it, and Docker's default json-file driver
     * having no max size, so a chatty container fills the disk and the first symptom
     * is every site on the server failing to write.
     *
     * Returns null when there is nothing to override — a generated file, whose
     * template already carries both.
     *
     * @param  array<string, mixed>  $context
     */
    private function writeOverride(Application $application, string $documentRoot, array $context): ?string
    {
        if ($this->pastedServices === []) {
            return $this->discardOverride($documentRoot, $context);
        }

        // **A one-click app's file is not a pasted file.** `contents()` takes the
        // pasted branch whenever the `compose` column is non-empty — and for a
        // one-click that column holds a file the PANEL rendered from the app's own
        // template, which already carries per-service limits: the app's ceiling on
        // the app service, and the database default on the database.
        //
        // Overriding it applies the app's numbers to every service, which is how a
        // Ghost installed at 640m gave its MySQL 640m as well while the file on
        // disk plainly said 512m. Found on the box by reading `docker inspect`
        // after trusting the file — the override had been doing this to memory
        // since one-click apps shipped, and the CPU quota would have joined it.
        //
        // So a one-click gets its own, narrower override: the app service only,
        // and only when the site's limit differs from what the file already says
        // ({@see recipeOverride()}).
        if ($this->panelRendered($application)) {
            return $this->recipeOverride($application, $documentRoot, $context);
        }

        $limit = (string) ($application->memory_limit
            ?: config('server.docker.default_memory_limit', '512m'));

        // No fallback, so a pasted file whose site has no CPU limit gets no `cpus`
        // key at all. Writing one with an empty value would be worse than omitting
        // it: Compose reads `cpus: ` as `0`, which means *no limit*, and the
        // override merges OVER the user's file — so the key that looks like a
        // limit would silently remove one they had set themselves.
        $cpus = $application->cpu_limit ?: null;

        $lines = ['# Written by the panel. Merged over compose.yml by `docker compose -f`.', 'services:'];

        foreach ($this->pastedServices as $service => $definition) {
            $lines[] = "  {$service}:";

            // The panel's number is a ceiling, not a replacement: a service that
            // asks for less than it keeps what it asked for.
            $limits = (array) ($definition['deploy']['resources']['limits'] ?? []);

            // Applied to EVERY service in the pasted file, unlike the generated
            // single-service template. There is no way to tell which of a
            // hand-written file's services is "the app", and the alternative —
            // limiting none of them — makes the field do nothing for exactly the
            // sites most likely to need it. Each service gets the quota, so the
            // number is a per-container ceiling and not a budget shared out.
            array_push($lines, ...$this->limitLines(
                $definition,
                $this->lowerMemory($limit, $definition['mem_limit'] ?? $limits['memory'] ?? null),
                $cpus !== null ? $this->lowerCpus((string) $cpus, $definition['cpus'] ?? $limits['cpus'] ?? null) : null,
            ));

            $lines[] = '    logging:';
            $lines[] = '      driver: json-file';
            $lines[] = '      options:';
            $lines[] = '        max-size: "10m"';
            $lines[] = '        max-file: "3"';
        }

        $path = rtrim($documentRoot, '/').'/compose.panel.yml';

        $written = $this->files->put($path, implode("\n", $lines)."\n", $context);

        if ($written->failed()) {
            throw new ProvisioningFailedException('compose_override', $written->reference);
        }

        return $path;
    }

    /**
     * The limit lines for one service of an override, under the key that service
     * already uses (RC-42).
     *
     * Compose refuses `mem_limit` next to `deploy.resources.limits.memory` with a
     * different value — "can't set distinct values" — and the override merges over
     * the file, so writing the other spelling made a file with its own `deploy:`
     * limits a site that never started. The same pair exists for `cpus`. A service
     * that spells one both ways gets both, with one value, which Compose accepts.
     *
     * A null value writes nothing for that limit. Every value is one the panel
     * produced — validated shorthand or a byte count — never text from the file.
     *
     * @param  array<string, mixed>  $definition  the service, resolved.
     * @return list<string>
     */
    private function limitLines(array $definition, ?string $memory, ?string $cpus): array
    {
        $limits = (array) ($definition['deploy']['resources']['limits'] ?? []);
        $lines = [];
        $deploy = [];

        if ($memory !== null) {
            // Quoted, unlike `mem_limit` (RC-42-FIX): Compose's schema takes a number
            // for `mem_limit` but only a string here, and refuses the whole file —
            // "deploy.resources.limits.memory must be a string" — over a byte count
            // YAML read as an integer.
            if (isset($limits['memory'])) {
                $deploy['memory'] = "\"{$memory}\"";
            }

            if (isset($definition['mem_limit']) || ! isset($limits['memory'])) {
                $lines[] = "    mem_limit: {$memory}";
            }
        }

        if ($cpus !== null) {
            if (isset($limits['cpus'])) {
                $deploy['cpus'] = $cpus;
            }

            if (isset($definition['cpus']) || ! isset($limits['cpus'])) {
                $lines[] = "    cpus: {$cpus}";
            }
        }

        if ($deploy !== []) {
            $lines[] = '    deploy:';
            $lines[] = '      resources:';
            $lines[] = '        limits:';

            foreach ($deploy as $key => $value) {
                $lines[] = "          {$key}: {$value}";
            }
        }

        return $lines;
    }

    /**
     * A one-click app's limits, changed after it was installed (F1).
     *
     * The file in the `compose` column was rendered from the recipe's template at
     * create, with the limit of that moment baked into the app service. Saving a
     * new `memory_limit` re-applied that same file, and the panel's override skips
     * one-click apps on purpose — so the new number reached nothing. Found in Docker
     * QA on a real box: NocoDB saved at 768m and kept running at 1 GiB.
     *
     * So this override names **the recipe's app service and nothing else**. The
     * database keeps its own limit, which is the reason the broad override was
     * switched off for one-click apps in the first place (Ghost's MySQL got the
     * app's 640m). And the site's value **replaces** the file's rather than capping
     * it, unlike a pasted file: raising an app from 768m to 1g has to raise it.
     *
     * Written only when the site's value differs from what the file already says,
     * so an app whose limits were never changed after install keeps running from
     * its own file exactly as before, with no override at all. A recipe with no
     * service marked `app`, or one the resolved file does not contain, gets none
     * either — today's behaviour, rather than a guess at which service to limit.
     *
     * @param  array<string, mixed>  $context
     */
    private function recipeOverride(Application $application, string $documentRoot, array $context): ?string
    {
        $recipe = app(RecipeRegistry::class)->find((string) $application->site_type);
        $apps = array_values(array_filter($recipe?->services ?? [], fn (array $s): bool => ($s['role'] ?? null) === 'app'));
        $service = (string) ($apps[0]['name'] ?? '');
        $definition = $service !== '' ? ($this->pastedServices[$service] ?? null) : null;

        if (count($apps) !== 1 || $definition === null) {
            return $this->discardOverride($documentRoot, $context);
        }

        $limits = (array) ($definition['deploy']['resources']['limits'] ?? []);

        $memory = filled($application->memory_limit) ? (string) $application->memory_limit : null;
        $own = $definition['mem_limit'] ?? $limits['memory'] ?? null;

        if ($memory !== null && is_scalar($own) && self::memoryBytes($memory) === self::memoryBytes((string) $own)) {
            $memory = null;
        }

        $cpus = filled($application->cpu_limit) ? (string) $application->cpu_limit : null;
        $ownCpus = $definition['cpus'] ?? $limits['cpus'] ?? null;

        if ($cpus !== null && is_numeric($ownCpus) && is_numeric($cpus) && (float) $cpus === (float) $ownCpus) {
            $cpus = null;
        }

        if ($memory === null && $cpus === null) {
            return $this->discardOverride($documentRoot, $context);
        }

        $lines = [
            '# Written by the panel. Merged over compose.yml by `docker compose -f`.',
            '# The app service only: the site\'s own limits, changed after install.',
            'services:',
            "  {$service}:",
            ...$this->limitLines($definition, $memory, $cpus),
        ];

        $path = rtrim($documentRoot, '/').'/compose.panel.yml';
        $written = $this->files->put($path, implode("\n", $lines)."\n", $context);

        if ($written->failed()) {
            throw new ProvisioningFailedException('compose_override', $written->reference);
        }

        return $path;
    }

    /**
     * The lower of the panel's memory ceiling and a service's own limit.
     *
     * `docker compose config` resolves every size to bytes, so the comparison is in
     * bytes. The answer is always a value the panel produced — its own validated
     * shorthand, or a byte count — never text copied out of the user's file, because
     * it is written into YAML unquoted.
     *
     * If either side does not parse, the panel's value is used: a ceiling that
     * cannot be compared is still a ceiling.
     */
    private function lowerMemory(string $ceiling, mixed $own): string
    {
        $ceilingBytes = self::memoryBytes($ceiling);
        $ownBytes = is_int($own) || is_string($own) ? self::memoryBytes((string) $own) : null;

        if ($ceilingBytes === null || $ownBytes === null || $ownBytes <= 0 || $ownBytes >= $ceilingBytes) {
            return $ceiling;
        }

        return (string) $ownBytes;
    }

    /**
     * Compose's own size shorthand, in bytes: `b`, `k`, `m`, `g`, any case, and a
     * bare number meaning bytes. Null for anything else.
     */
    private static function memoryBytes(string $value): ?int
    {
        if (preg_match('/^(\d+)([bkmg])?$/i', trim($value), $matches) !== 1) {
            return null;
        }

        $unit = match (strtolower($matches[2] ?? '')) {
            'k' => 1024,
            'm' => 1024 * 1024,
            'g' => 1024 * 1024 * 1024,
            default => 1,
        };

        // A number too big for an integer is not a size anybody can compare.
        if (strlen($matches[1]) > 18 || (int) $matches[1] > intdiv(PHP_INT_MAX, $unit)) {
            return null;
        }

        return (int) $matches[1] * $unit;
    }

    /**
     * The lower of the site's CPU quota and a service's own.
     *
     * A service quota of `0` is Docker's spelling of "no limit", so it never wins.
     * As with memory, an own value that is not a plain number leaves the site's.
     */
    private function lowerCpus(string $ceiling, mixed $own): string
    {
        $own = is_int($own) || is_float($own) || is_string($own) ? trim((string) $own) : '';

        if (! is_numeric($ceiling) || preg_match('/^\d+(\.\d+)?$/', $own) !== 1
            || (float) $own <= 0 || (float) $own >= (float) $ceiling) {
            return $ceiling;
        }

        return $own;
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
            timeout: self::QUERY_TIMEOUT,
        );

        return $result->answered && trim($result->output()) !== '';
    }

    public function start(Application $application, string $documentRoot): ServerOpsResult
    {
        // The override is rewritten here too, not assumed to be on disk. `start()`
        // is reachable on a site provisioned before it existed, and passing `-f` for
        // a file that is not there fails the whole command.
        $context = ['feature' => 'application', 'op' => 'compose_override', 'application' => $application->id];

        // Re-render so `$pastedServices` is populated; the file itself is already
        // correct on disk and this is a read of the record, not of the container.
        $this->contents($application, $documentRoot);

        return $this->forgetReadiness($application, $this->compose(
            $application,
            $documentRoot,
            ['up', '-d'],
            'compose_up',
            $this->writeOverride($application, $documentRoot, $context),
        ));
    }

    public function stop(Application $application, string $documentRoot): ServerOpsResult
    {
        return $this->forgetReadiness($application, $this->compose($application, $documentRoot, ['stop'], 'compose_stop'));
    }

    public function restart(Application $application, string $documentRoot): ServerOpsResult
    {
        return $this->forgetReadiness($application, $this->compose($application, $documentRoot, ['restart'], 'compose_restart'));
    }

    /**
     * Drop what the last readiness check saw once the containers have been
     * started, stopped or restarted without one (DS-09). `running` from the
     * deploy before a Stop is a green badge over a 502; no check at all is
     * what these paths have, and null says exactly that. A failure stays: it
     * is still the last thing known to have gone wrong.
     */
    private function forgetReadiness(Application $application, ServerOpsResult $result): ServerOpsResult
    {
        if ($application->container_status === 'running') {
            $application->forceFill(['container_status' => null])->save();
        }

        return $result;
    }

    /**
     * Take it down and remove what it left behind — except the data.
     *
     * No `--volumes`. Removing an application must not silently destroy the
     * database inside it; a container's volumes outliving it is recoverable,
     * and the reverse is not.
     */
    public function remove(Application $application, string $documentRoot, bool $withVolumes = false): void
    {
        $arguments = ['down', '--remove-orphans'];

        // `--volumes` only when the caller opted in, and it removes a different set
        // from the panel's own cleanup — the two are complementary, not overlapping.
        //
        // The panel's volumes are declared `external: true` in the generated file,
        // so `down --volumes` does not touch them; `DeleteApplicationDockerResources`
        // removes those, and only after checking no other site mounts them.
        // What compose declares in a PASTED file is the panel's blind spot: it never
        // recorded those on `volume_mounts`, so nothing knew to remove them and they
        // outlived the site as orphans named after a project that no longer exists.
        //
        // Measured on a real box: a Miniflux site pasted with its own `mfdata:`
        // volume was deleted with "remove this site's Docker objects" ticked, and
        // `sv-app-7_mfdata` was still there afterwards.
        if ($withVolumes) {
            $arguments[] = '--volumes';
        }

        $this->compose($application, $documentRoot, $arguments, 'compose_down');
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
            timeout: self::QUERY_TIMEOUT,
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
    private function compose(
        Application $application,
        string $documentRoot,
        array $arguments,
        string $op,
        ?string $override = null,
        ?string $auth = null,
        ?int $timeout = null,
    ): ServerOpsResult {
        // The override is passed only where it matters, which is container
        // CREATION — `up`. `down`, `logs`, `ps`, `stop` and `restart` do not read
        // limits, and passing `-f` for a file that may not exist on a site
        // provisioned before this feature would fail the command outright. That is
        // the whole reason this is a parameter rather than part of the base command.
        $files = ['-f', $this->composePath($application, $documentRoot)];

        if ($override !== null) {
            $files = array_merge($files, ['-f', $override]);
        }

        // `--config` is a flag of the docker CLI itself, so it goes BEFORE the
        // `compose` subcommand — after it, compose would read it as one of its
        // own and fail. Verified against both `compose up` and `compose pull`:
        // the plugin does honour the CLI's credential store.
        //
        // The value is a directory path, not a secret, so having it in argv is
        // fine. The token inside the file it names is not there.
        $prefix = $auth === null ? ['docker'] : ['docker', '--config', $auth];

        return $this->serverOps->run(
            array_merge($prefix, [
                'compose',
            ], $files, [
                '-p', $this->project($application),
            ], $arguments),
            ['feature' => 'application', 'op' => $op, 'application' => $application->id],
            timeout: $timeout ?? (int) config('server.docker.command_timeout', 600),
        );
    }
}
