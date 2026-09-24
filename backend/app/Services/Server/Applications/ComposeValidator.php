<?php

namespace App\Services\Server\Applications;

use App\Services\Server\ServerOps;

/**
 * Decides whether a compose file somebody pasted is safe to run here.
 *
 * **Parsed by Docker itself**, with `docker compose config --format json`, not
 * by a YAML library. That is the important decision in this class and it is a
 * security one: a validator whose parser differs from the executor's parser is
 * a bypass waiting to be found. Anything my parser reads differently from the
 * daemon's is a way past every rule below. Using the same parser makes that
 * class of hole impossible rather than unlikely.
 *
 * It also normalises, which removes the other half of the problem. Compose
 * accepts `- /:/host` and `- {type: bind, source: /, target: /host}` and they
 * mean the same thing; `config` resolves both into the second shape, so each
 * rule here is written once against one canonical form instead of guessing at
 * five spellings.
 *
 * `config` parses and interpolates. It does not pull, create or start
 * anything.
 */
class ComposeValidator
{
    /**
     * Service keys that hand over the host, whatever else the file says.
     *
     * Not a stylistic list. Each of these is a documented way out of the
     * container and onto the machine:
     *
     *  - `privileged` — all capabilities, all devices. It is root on the host
     *    with extra steps.
     *  - `cap_add` — `SYS_ADMIN` alone is enough to mount filesystems.
     *  - `devices` — raw block devices; `/dev/sda` is every file on the disk,
     *    including ones the container has no mount for.
     *  - `pid` / `ipc` / `userns_mode` — sharing the host's namespaces, which
     *    is how you read another process's memory or signal it.
     *  - `security_opt` — where `apparmor:unconfined` and
     *    `seccomp:unconfined` are spelled.
     *  - `network_mode` — `host` puts the container on the host's stack, past
     *    the loopback publishing this panel relies on and past the firewall
     *    with it.
     *  - `cgroup_parent` — escaping the resource limits the panel sets.
     *
     * @var array<string, string>
     */
    private const FORBIDDEN_KEYS = [
        'privileged' => 'privileged',
        'cap_add' => 'cap_add',
        'devices' => 'devices',
        'pid' => 'namespace',
        'ipc' => 'namespace',
        'userns_mode' => 'namespace',
        'security_opt' => 'security_opt',
        'network_mode' => 'network_mode',
        'cgroup_parent' => 'cgroup_parent',
    ];

    public function __construct(private ServerOps $serverOps) {}

    /**
     * @return array{ok: bool, errors: list<string>, resolved: array<string, mixed>|null}
     */
    public function validate(string $compose, string $documentRoot): array
    {
        $parsed = $this->parse($compose, $documentRoot);

        if ($parsed === null) {
            return ['ok' => false, 'errors' => [__('errors/application.compose_unparsable')], 'resolved' => null];
        }

        $errors = [];
        $services = $parsed['services'] ?? [];

        if (! is_array($services) || $services === []) {
            $errors[] = __('errors/application.compose_no_services');
        }

        foreach ($services as $name => $service) {
            if (! is_array($service)) {
                continue;
            }

            foreach (self::FORBIDDEN_KEYS as $key => $reason) {
                if (array_key_exists($key, $service)) {
                    $errors[] = __("errors/application.compose_forbidden.{$reason}", ['service' => $name]);
                }
            }

            $errors = array_merge(
                $errors,
                $this->bindMountErrors((string) $name, $service, $documentRoot),
                $this->portErrors((string) $name, $service),
            );
        }

        return [
            'ok' => $errors === [],
            // Unique: one forbidden key repeated across five services is one
            // problem to fix, and five identical lines reads as five.
            'errors' => array_values(array_unique($errors)),
            'resolved' => $parsed,
        ];
    }

    /**
     * The host port nginx should proxy to, from a resolved compose document.
     *
     * The panel cannot allocate this one. With a generated file the panel
     * picks the port and writes it in; with a pasted file the user has
     * already chosen, and proxying to a port the panel allocated instead
     * would point nginx at nothing — the container is listening where the
     * compose file says, not where the panel wished.
     *
     * One published port is unambiguous. Several is legitimate — an
     * application and its metrics endpoint — and then `container_port`
     * disambiguates by naming the port *inside* the container, which is the
     * number the user knows. Only when neither settles it does this give up,
     * and the caller turns that into a message asking for the container port
     * rather than guessing.
     *
     * @param  array<string, mixed>  $resolved
     */
    public function publishedPort(array $resolved, ?int $containerPort = null): ?int
    {
        $published = [];

        foreach (($resolved['services'] ?? []) as $service) {
            foreach ((array) ($service['ports'] ?? []) as $port) {
                if (is_array($port) && isset($port['published'])) {
                    $published[] = [
                        'host' => (int) $port['published'],
                        'target' => (int) ($port['target'] ?? 0),
                    ];
                }
            }
        }

        if ($published === []) {
            return null;
        }

        if (count($published) === 1) {
            return $published[0]['host'];
        }

        foreach ($published as $entry) {
            if ($containerPort !== null && $entry['target'] === $containerPort) {
                return $entry['host'];
            }
        }

        return null;
    }

    /**
     * Bind mounts must stay inside the application's own directory.
     *
     * The check is on the *resolved* path, and that matters: `source` may be
     * relative, and `../..` climbs out of the tree while looking local. Named
     * volumes are untouched — they are Docker's to place and cannot point at
     * the host filesystem.
     *
     * @param  array<string, mixed>  $service
     * @return list<string>
     */
    private function bindMountErrors(string $name, array $service, string $documentRoot): array
    {
        $errors = [];
        $root = rtrim($documentRoot, '/');

        foreach ((array) ($service['volumes'] ?? []) as $volume) {
            if (! is_array($volume) || ($volume['type'] ?? null) !== 'bind') {
                continue;
            }

            $source = (string) ($volume['source'] ?? '');

            // Compare on a normalised absolute path, with the separator
            // appended to both sides. Without the separator `/home/shop-evil`
            // passes a `str_starts_with('/home/shop')` check.
            $resolved = $this->normalise($source, $root);

            if ($resolved !== $root && ! str_starts_with($resolved.'/', $root.'/')) {
                $errors[] = __('errors/application.compose_bind_outside', [
                    'service' => $name,
                    'path' => $source,
                ]);
            }
        }

        return $errors;
    }

    /**
     * Published ports must bind loopback.
     *
     * A missing `host_ip` in the resolved document means every address, which
     * is what `"8080:80"` expands to — the form everyone writes. Docker's
     * rules sit ahead of the ones ufw manages, so that port is reachable from
     * the internet while the panel's Firewall page reports it closed.
     *
     * @param  array<string, mixed>  $service
     * @return list<string>
     */
    private function portErrors(string $name, array $service): array
    {
        $errors = [];

        foreach ((array) ($service['ports'] ?? []) as $port) {
            if (! is_array($port)) {
                continue;
            }

            $host = (string) ($port['host_ip'] ?? '');

            if (! in_array($host, ['127.0.0.1', '::1'], true)) {
                $errors[] = __('errors/application.compose_port_public', [
                    'service' => $name,
                    'port' => (string) ($port['published'] ?? '?'),
                ]);
            }
        }

        return $errors;
    }

    /**
     * Absolute, with `.` and `..` collapsed, without touching the filesystem.
     *
     * `realpath()` is wrong here: it returns false for a path that does not
     * exist yet, and a bind mount to a directory compose will create is
     * legitimate. It also follows symlinks, which means the answer depends on
     * what is on disk at validation time rather than on what was written.
     */
    private function normalise(string $path, string $root): string
    {
        $path = str_starts_with($path, '/') ? $path : $root.'/'.$path;

        $parts = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($parts);

                continue;
            }

            $parts[] = $segment;
        }

        return '/'.implode('/', $parts);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parse(string $compose, string $documentRoot): ?array
    {
        $result = $this->serverOps->run(
            ['docker', 'compose', '-f', '-', 'config', '--format', 'json'],
            ['feature' => 'application', 'op' => 'compose_validate'],
            timeout: 30,
            input: $compose,
            // The working directory is load-bearing, not tidiness. Compose
            // resolves a relative bind source against the CWD of the process
            // reading the file — measured: from /home/ubuntu, `./data` became
            // `/home/ubuntu/data`. Validate from anywhere other than the
            // directory the deploy will run in and the two resolve different
            // paths, which is the validation/execution mismatch this whole
            // class is built to avoid.
            cwd: $documentRoot,
        );

        if (! $result->answered) {
            return null;
        }

        $decoded = json_decode($result->output(), true);

        return is_array($decoded) ? $decoded : null;
    }
}
