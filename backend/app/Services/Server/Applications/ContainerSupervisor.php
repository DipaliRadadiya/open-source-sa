<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Server\Docker\RegistryAuth;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
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
     * Service names from the last pasted compose file this instance rendered.
     *
     * Set by `contents()` and read by `writeOverride()`, because the override has
     * to name each service and only the resolved document knows what they are.
     * Empty for a generated file, whose template carries its own limits.
     *
     * @var list<string>
     */
    private array $pastedServices = [];

    public function apply(Application $application, string $documentRoot, bool $start = true): void
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
        $result = $this->registryAuth->with(
            $application->registry,
            ['feature' => 'application', 'application' => $application->id],
            fn (?string $auth): ServerOpsResult => $this->compose(
                $application,
                $documentRoot,
                ['up', '-d', '--remove-orphans'],
                'compose_up',
                $override,
                $auth,
            ),
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
    }

    private function readiness(): ContainerReadinessCheck
    {
        return $this->readiness ??= new ContainerReadinessCheck($this->serverOps);
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
     * Unreadable output answers false. This runs after a successful `up`, and
     * failing a deploy because a status query did not parse would turn a working
     * site into a reported failure — the opposite of the mistake it exists to stop.
     */
    public function crashLooping(Application $application, string $documentRoot): bool
    {
        $result = $this->compose($application, $documentRoot, ['ps', '--format', 'json'], 'compose_ps_state');

        if (! $result->answered) {
            return false;
        }

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

            if ($name !== '' && $this->restartedSinceStarting($name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Has this container restarted since it was created?
     *
     * `RestartCount` and `StartedAt` in one call, because two calls to inspect the
     * same container is two chances for the answer to change underneath.
     */
    private function restartedSinceStarting(string $name): bool
    {
        $result = $this->serverOps->run(
            ['docker', 'inspect', '--format', '{{.RestartCount}} {{.State.StartedAt}}', $name],
            ['feature' => 'application', 'op' => 'container_restarts'],
            timeout: 20,
        );

        if (! $result->answered) {
            return false;
        }

        $parts = preg_split('/\s+/', trim($result->output()));
        $count = (int) ($parts[0] ?? 0);

        if ($count < 1) {
            return false;
        }

        $startedAt = strtotime($parts[1] ?? '');

        // Only a container that started recently. An older one's restarts are
        // history, not a loop — see the note on the caller.
        return $startedAt !== false && (time() - $startedAt) < 120;
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
                $application->forceFill(['app_port' => $port])->save();
            }

            // The service names the panel has to protect, taken from the
            // RESOLVED document rather than by reading the user's text: compose
            // accepts `extends`, anchors and profiles, and the resolved document
            // is the only place their result is visible.
            $this->pastedServices = array_keys((array) ($verdict['resolved']['services'] ?? []));

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
        $values = [
            'project' => $this->project($application),
            'image' => (string) $application->image,
            'appPort' => (int) $application->app_port,
            'containerPort' => (int) ($application->container_port ?: 80),
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
     * True for every one-click app, because `DockerAppInstaller` renders that
     * app's template into the column — so the file is the panel's own work and
     * already carries everything the override exists to add.
     *
     * Asked of `docker_apps`, the same registry the installer reads the image
     * from, rather than by matching the site type against a class. A type that is
     * in that list is by definition one whose compose file the panel generates,
     * and the two cannot drift: a new one-click has to be registered there or it
     * has no image.
     */
    private function panelRendered(Application $application): bool
    {
        return config("server.docker_apps.{$application->site_type}") !== null;
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
        if ($this->panelRendered($application)) {
            return $this->discardOverride($documentRoot, $context);
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

        foreach ($this->pastedServices as $service) {
            $lines[] = "  {$service}:";
            $lines[] = "    mem_limit: {$limit}";

            // Applied to EVERY service in the pasted file, unlike the generated
            // single-service template. There is no way to tell which of a
            // hand-written file's services is "the app", and the alternative —
            // limiting none of them — makes the field do nothing for exactly the
            // sites most likely to need it. Each service gets the quota, so the
            // number is a per-container ceiling and not a budget shared out.
            if ($cpus !== null) {
                $lines[] = "    cpus: {$cpus}";
            }
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
        // The override is rewritten here too, not assumed to be on disk. `start()`
        // is reachable on a site provisioned before it existed, and passing `-f` for
        // a file that is not there fails the whole command.
        $context = ['feature' => 'application', 'op' => 'compose_override', 'application' => $application->id];

        // Re-render so `$pastedServices` is populated; the file itself is already
        // correct on disk and this is a read of the record, not of the container.
        $this->contents($application, $documentRoot);

        return $this->compose(
            $application,
            $documentRoot,
            ['up', '-d'],
            'compose_up',
            $this->writeOverride($application, $documentRoot, $context),
        );
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
            timeout: (int) config('server.docker.command_timeout', 600),
        );
    }
}
