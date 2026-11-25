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
 * Runs an application that is a container (counterpart to {@see ProcessSupervisor}).
 *
 * Verifies the container actually came up: `docker compose up -d` exits 0 for one that starts and
 * immediately dies, which would otherwise show as a running app in a restart loop.
 */
class ContainerSupervisor
{
    /** Ceiling for `ps`/`logs` queries; `up`/`pull` use `server.docker.command_timeout`. */
    public const QUERY_TIMEOUT = 30;

    public function __construct(
        private ServerOps $serverOps,
        private ManagedFile $files,
        private ComposeValidator $validator,
        private RegistryAuth $registryAuth,
        // Optional so a hand-built supervisor still works; it checks through its own ServerOps.
        private ?ContainerReadinessCheck $readiness = null,
    ) {}

    /**
     * The compose project name, also the container name prefix. Derived from the id, not the slug:
     * a renamed slug would orphan the old, still-running project.
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
     * Services of the last pasted compose file rendered (name => resolved definition), set by
     * `contents()` for `writeOverride()`. Empty for a generated file, whose template has its own limits.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $pastedServices = [];

    /** `$install` marks the first install: its `up` gets `server.docker.install_timeout`. */
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

        // The credential exists only inside `with()`; removed in a `finally` even if the pull fails.
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
            // `fromResult` names the one non-obvious failure here (a registry refusing the pull).
            throw ProvisioningFailedException::fromResult('container_start', $result);
        }

        // A described site (image or pasted file) waits until it answers on its port; a failure is stored
        // on the site with the reason and container log. One-click installers wait for first boot themselves.
        if (! $this->panelRendered($application) && (int) $application->app_port > 0) {
            $this->readiness()->verify($application, $documentRoot, $this);

            return;
        }

        if (! $this->running($application, $documentRoot)) {
            throw new ProvisioningFailedException('container_exited', $result->reference);
        }

        // "Running" at one instant is not enough: a container in a restart loop is in the `running`
        // state for part of every cycle, so a single `ps` sample can call a crash-looping site healthy.
        if ($this->crashLooping($application, $documentRoot)) {
            throw new ProvisioningFailedException('container_restarting', $result->reference, 'container_restarting');
        }

        $this->recordUp($application);
    }

    /** Record `running`, the word an image site's readiness check stores. */
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
     * A port clash during `compose up` leaves a container on no network, and the next `up` just starts
     * it again with no published port or route to its database. Zero networks is proof, since the panel
     * refuses `network_mode`. Only those services are recreated (`--no-deps`); volumes survive.
     *
     * Null when there was nothing to repair or Docker could not be asked.
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
     * Two signals: state `restarting`, and a restart count above zero on a container that has just
     * started (`apply()` also runs on sites up for weeks, where an old crash leaves a non-zero count).
     * The second is a suspicion, not a verdict: one restart is the restart policy doing its job, so the
     * suspect is sampled again after `server.docker.crash_loop_confirm_seconds` and is a loop only if it
     * restarted again or stopped running.
     *
     * Unreadable output answers false: failing a deploy over an unparsed status query would turn a
     * working site into a reported failure.
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

            // `compose ps --format json` carries no restart count, so ask Docker directly.
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

            // Unreadable the second time is unreadable, not a loop.
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
     * What the image's own HEALTHCHECK says, across this project.
     *
     * `unhealthy` if any container is, then `starting`, then `healthy`. Null when none has a healthcheck
     * or the question went unanswered: "Docker has no opinion", never "fine", so the caller asks the port.
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
     * `compose ps --format json`, one decoded row per container (older releases printed one array).
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
     * Restart count, last start time and current state of a container, or null when Docker did not
     * answer. One call, so the values come from the same moment; status is last so a missing one
     * leaves the other two readable.
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

        // A gone container or non-Docker output says nothing about restarts: null, not a guessed 0.
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
     * Restarted at least once, within the last two minutes: older restarts are history, see {@see crashLooping()}.
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
     * Its own action because **`up` alone does not do it**: Compose reuses a locally cached image, so a
     * floating tag stays on its first-pulled layers. `up` still runs after `pull` to recreate the
     * container onto an image that did change.
     *
     * @throws ProvisioningFailedException
     */
    public function pull(Application $application, string $documentRoot): void
    {
        // Re-render first, as `start()` does: `writeOverride()` reads `$pastedServices`, which only
        // `contents()` fills. Without it the override is deleted and the limits and log cap are lost.
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

        // A redeploy gets the same readiness check as the first deploy, and clears its failure.
        if (! $this->panelRendered($application) && (int) $application->app_port > 0) {
            $this->readiness()->verify($application, $documentRoot, $this);

            return;
        }

        if (! $this->running($application, $documentRoot)) {
            throw new ProvisioningFailedException('container_exited', '');
        }

        $this->recordUp($application);
    }

    /**
     * The file to write: the user's, or one built from the fields.
     *
     * A user-supplied file is **validated again here**, not only on save: a restore, an import or a
     * direct edit also reach this method.
     *
     * @throws ProvisioningFailedException
     */
    private function contents(Application $application, string $documentRoot): string
    {
        // Reset per render: a rollback calls this again on the same instance (pasted, then generated), and
        // a stale list would name services the second file lacks.
        $this->pastedServices = [];

        $compose = (string) $application->compose;

        if (trim($compose) !== '') {
            $verdict = $this->validator->validate($compose, $documentRoot);

            if (! $verdict['ok']) {
                throw new ProvisioningFailedException('compose_invalid', '', implode(' ', $verdict['errors']));
            }

            // The port nginx proxies to comes from the file, not the allocator: a pasted file's container
            // listens where the file says. Recorded so the vhost and the container cannot disagree.
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
                // Held by another site: the forms refuse that, but a row they never saw would otherwise hit
                // the unique index and leave a `failed` site with no reason. A reason code, titled per viewer.
                if (Application::query()->where('app_port', $port)->whereKeyNot($application->getKey())->exists()) {
                    throw new ProvisioningFailedException('compose_port_taken', '', 'compose_port_taken');
                }

                $application->forceFill(['app_port' => $port])->save();
            }

            // Names come from the RESOLVED document (compose accepts `extends`, anchors and profiles).
            // Definitions are kept too: a service that sets its own limit is overridden under the same key.
            $this->pastedServices = array_map(
                fn (mixed $definition): array => (array) $definition,
                (array) ($verdict['resolved']['services'] ?? []),
            );

            // The validator's file, not the user's: they differ when a port was published to every
            // address and the panel bound it to loopback. The row keeps what was typed.
            return $verdict['compose'];
        }

        return $this->generated($application, $documentRoot);
    }

    /**
     * The compose file the panel would write from this site's fields. Public and side-effect free:
     * the editor shows it before anything is edited, and a GET must not rewrite `app_port`.
     */
    public function generated(Application $application, string $documentRoot): string
    {
        // No port, no file, never a default: `?: 80` rendered a site with no container port as
        // listening on 80 while nginx proxied to nothing. Guards rows that skip the forms.
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
            // No fallback, unlike memory: null means no CPU quota at all, so a site that never had one
            // renders exactly as before. A default would cap every existing container site.
            'cpuLimit' => $application->cpu_limit ?: null,
            // Null means Docker's default bridge. Only reached on this branch: a pasted file that
            // names its own networks must not get one appended.
            'network' => $application->docker_network ?: null,
            // The site's name on that network. Unique, unlike the service name `app`, which resolves
            // to an arbitrary site when two share a network.
            'alias' => $application->slug,
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
     * The template writes values as bare YAML scalars and Blade escaping leaves newlines alone, so a
     * line break would turn the text after it into a new key (`privileged: true`, a bind of `/`) in a
     * file nothing validates afterwards. This is the one check every field passes through.
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
     * A leftover file is inert but states limits the container does not have. A failed delete is not a
     * provisioning failure: refusing to start a site over a stale file would trade a cosmetic problem
     * for an outage.
     *
     * @param  array<string, mixed>  $context
     */
    private function discardOverride(string $documentRoot, array $context): ?string
    {
        $this->files->delete(rtrim($documentRoot, '/').'/compose.panel.yml', $context);

        return null;
    }

    /**
     * Did the panel write this site's `compose` column itself? True for every recipe app, including
     * deprecated recipes that must still resolve for existing sites.
     */
    public function panelRendered(Application $application): bool
    {
        return app(RecipeRegistry::class)->has((string) $application->site_type);
    }

    /**
     * Write the panel's own compose override, and return its path.
     *
     * An override file rather than editing the user's YAML (re-emitting it loses comments and reorders
     * keys); Compose merges a second `-f` over the first. Not optional: `ComposeValidator` adds no
     * `mem_limit` or `logging`, so a hand-written file would run with no memory ceiling and unbounded
     * logs. Returns null when there is nothing to override (a generated file carries both itself).
     *
     * @param  array<string, mixed>  $context
     */
    private function writeOverride(Application $application, string $documentRoot, array $context): ?string
    {
        if ($this->pastedServices === []) {
            return $this->discardOverride($documentRoot, $context);
        }

        // A one-click app's file is rendered by the PANEL and already carries per-service limits. The
        // broad override would apply the app's number to every service (a Ghost at 640m gave its MySQL
        // 640m too), so it gets a narrower one: the app service only ({@see recipeOverride()}).
        if ($this->panelRendered($application)) {
            return $this->recipeOverride($application, $documentRoot, $context);
        }

        $limit = (string) ($application->memory_limit
            ?: config('server.docker.default_memory_limit', '512m'));

        // No fallback: a pasted file whose site has no CPU limit gets no `cpus` key. An empty `cpus: `
        // reads as `0` (no limit) and, merged OVER the file, would remove a limit the user set.
        $cpus = $application->cpu_limit ?: null;

        $lines = ['# Written by the panel. Merged over compose.yml by `docker compose -f`.', 'services:'];

        foreach ($this->pastedServices as $service => $definition) {
            $lines[] = "  {$service}:";

            // The panel's number is a ceiling: a service asking for less keeps what it asked for.
            $limits = (array) ($definition['deploy']['resources']['limits'] ?? []);

            // Applied to EVERY service: there is no telling which of a hand-written file's services is
            // "the app", so the number is a per-container ceiling, not a shared budget.
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
     * The limit lines for one service of an override, under the key that service already uses.
     *
     * Compose refuses `mem_limit` next to `deploy.resources.limits.memory` with a different value, and
     * likewise for `cpus`; a service that spells one both ways gets both, with one value. Every value is
     * one the panel produced (validated shorthand or a byte count), never text from the file.
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
            // Quoted, unlike `mem_limit`: Compose's schema takes a string here and refuses the file if
            // YAML reads a byte count as an integer.
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
     * A one-click app's limits, changed after it was installed.
     *
     * The `compose` column keeps the limit of install time and the broad override skips one-click apps,
     * so a new `memory_limit` would reach nothing. This override names the recipe's app service only (the
     * database keeps its limit), and the site's value **replaces** the file's rather than capping it.
     * Written only when it differs from the file's.
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
     * The lower of the panel's memory ceiling and a service's own limit, compared in bytes. Always a
     * value the panel produced, never text from the user's file: it is written into YAML unquoted.
     * If either side does not parse, the panel's value is used.
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

    /** Compose's size shorthand (`b`, `k`, `m`, `g`, bare bytes) in bytes; null otherwise. */
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
     * The lower of the site's CPU quota and a service's own. A service quota of `0` is Docker's
     * "no limit", so it never wins; a non-numeric own value leaves the site's.
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

    /** Is anything running? `--status running`, as compose also lists exited containers. */
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
        // Rewrite the override too: a site provisioned before it existed has no file, and `-f` for it fails.
        $context = ['feature' => 'application', 'op' => 'compose_override', 'application' => $application->id];

        // Re-render so `$pastedServices` is populated; the file on disk is already correct.
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
     * Restart every container in place, and fail if nothing is up afterwards: `compose restart` exits 0
     * for a service that stays stopped.
     */
    public function restartAndConfirm(Application $application, string $documentRoot): ServerOpsResult
    {
        $restarted = $this->restart($application, $documentRoot);

        if ($restarted->failed()) {
            return $restarted;
        }

        $ps = $this->compose(
            $application,
            $documentRoot,
            ['ps', '--status', 'running', '--quiet'],
            'compose_ps',
            timeout: self::QUERY_TIMEOUT,
        );

        if ($ps->answered && trim($ps->output()) !== '') {
            return $restarted;
        }

        return new ServerOpsResult(ok: false, reference: $ps->reference, result: $ps->result);
    }

    /** Is the Docker daemon answering at all? Asked first so "Docker is down" is reported as that. */
    public function daemonAnswers(): bool
    {
        return $this->serverOps->run(
            ['docker', 'info', '--format', '{{.ServerVersion}}'],
            ['feature' => 'application', 'op' => 'docker_info'],
            timeout: self::QUERY_TIMEOUT,
        )->ok;
    }

    /**
     * Drop the last readiness result when containers are started, stopped or restarted without a check:
     * a stale `running` would be a green badge over a 502. A failure stays.
     */
    private function forgetReadiness(Application $application, ServerOpsResult $result): ServerOpsResult
    {
        if ($application->container_status === 'running') {
            $application->forceFill(['container_status' => null])->save();
        }

        return $result;
    }

    /** Take it down and remove what it left behind, except the data (volumes only when asked). */
    public function remove(Application $application, string $documentRoot, bool $withVolumes = false): void
    {
        $arguments = ['down', '--remove-orphans'];

        // `--volumes` only on opt-in. The panel's own volumes are `external: true`, so `down --volumes`
        // skips them (`DeleteApplicationDockerResources` removes those). Volumes declared in a PASTED
        // file are not recorded on `volume_mounts`, so only `--volumes` removes them, else they orphan.
        if ($withVolumes) {
            $arguments[] = '--volumes';
        }

        $this->compose($application, $documentRoot, $arguments, 'compose_down');
    }

    /** Recent output, always bounded with `--tail`. */
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
     * `--no-color` only covers compose's own colouring, not what the process inside prints; unstripped,
     * the log viewer shows literal `[36m` noise. Stripped rather than rendered, as every other source
     * there is plain text. CSI covers colour and cursor sequences, OSC window-title ones.
     */
    private function plain(string $output): string
    {
        return (string) preg_replace(
            [
                // CSI: ESC [ … final-byte — colours, cursor moves, erases.
                '/\x1B\[[0-9;?]*[ -\/]*[@-~]/',
                // OSC: ESC ] … terminated by BEL or ST (ESC backslash): titles and hyperlinks. The ST
                // alternative needs four backslashes: two survive PHP's quoting, two make one regex literal.
                '/\x1B\][^\x07]*(?:\x07|\x1B\\\\)/',
                // Two-character escapes left over (charset selection and friends); letters only so a
                // stray ESC cannot eat the next character.
                '/\x1B[()#][0-9A-Za-z]/',
            ],
            '',
            $output,
        );
    }

    /**
     * Every compose call goes through here.
     *
     * Run as root via the panel's sudoers grant, not as the site user: `docker` group membership is
     * equivalent to root (a member can bind mount `/`). Always `-f` and `-p` explicitly: compose infers
     * the project from the directory, and two apps sharing a directory basename would tear down each
     * other's containers.
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
        // The override is passed only for container CREATION (`up`): the other commands read no limits,
        // and `-f` for a file missing on a site provisioned earlier would fail the command.
        $files = ['-f', $this->composePath($application, $documentRoot)];

        if ($override !== null) {
            $files = array_merge($files, ['-f', $override]);
        }

        // `--config` is a flag of the docker CLI itself, so it goes BEFORE `compose`. The value is a
        // directory path, not a secret, so having it in argv is fine.
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
