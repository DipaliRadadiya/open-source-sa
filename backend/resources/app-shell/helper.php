<?php

/*
 * Container login for a Docker app's own system user (RC-28).
 *
 * Managed by the Control panel. Rendered by `artisan containers:sync-shell-access`
 * from backend/resources/app-shell/helper.php and installed root:root 0755;
 * edits are lost on the next panel update.
 *
 * sshd's `Match Group sv-app-shell` block forces every login of a member through
 * the login wrapper, which runs this as root with `sudo -n` and hands it the
 * user's original command as ONE argument. From there:
 *
 *   ssh -t u@ip                       a shell in the main container of u's app
 *   ssh u@ip '<command>'              `sh -c '<command>'` in that container
 *   ssh -t u@ip @<service>            the same, in another container of the app
 *   ssh u@ip @<service> <command>
 *   ssh u@ip @<app>[/<service>] …     picks one app when u owns several
 *   ssh u@ip @logs [<target>] [--tail N] [--follow]
 *   ssh u@ip @list
 *
 * The rules this file exists to keep:
 *
 *   - Who is asking comes from sudo (SUDO_USER), and what they may reach comes
 *     from a root-only map the panel writes. Nothing else is trusted: not the
 *     environment, not the working directory, not a name the user typed.
 *   - No docker option ever comes from the user. Only two fixed command shapes
 *     run: `docker exec [-i] [-t] <name> sh -c <string>` and
 *     `docker logs --tail N [-f] <name>`. The user's text is the `sh -c` string,
 *     interpreted by the container's shell and never by one on the host.
 *   - A container that is privileged or holds the Docker socket is refused even
 *     when it is the caller's own: a shell in it is a shell on the host.
 *   - One syslog line per session (auth facility) says who reached what.
 */

declare(strict_types=1);

namespace PanelAppShell;

/** Replaced when rendered; see AppShellFiles::helper(). */
const MAP = '/etc/panel-app-shell/map.json';

const DOCKER = ['/usr/bin/docker', '/usr/local/bin/docker'];

/** What Docker accepts as a container name (the same rule as ContainerAccess). */
const CONTAINER_NAME = '/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/D';

/** An app or service name as typed after `@` (Compose allows capitals in a service). */
const TOKEN = '/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}$/D';

/** A login name as `useradd` makes them. */
const USERNAME = '/^[a-z_][a-z0-9_-]{0,31}$/D';

const DEFAULT_TAIL = 200;

const MAX_TAIL = 100000;

/** bash when the image has it, sh when it does not. A fixed string, never the user's. */
const SHELL = 'if command -v bash >/dev/null 2>&1; then exec bash; fi; exec sh';

const NOT_YOURS = 'not your app';

/**
 * The caller's own apps, from the map, with every name re-checked.
 *
 * @param  array<mixed>  $map
 * @return list<array{app: string, containers: list<array{service: string, name: string, main: bool}>}>
 */
function appsOf(string $user, array $map): array
{
    $apps = [];

    foreach ((array) ($map['apps'] ?? []) as $entry) {
        if (! is_array($entry) || ($entry['owner'] ?? null) !== $user) {
            continue;
        }

        $app = $entry['app'] ?? null;

        if (! is_string($app) || preg_match(TOKEN, $app) !== 1) {
            continue;
        }

        $containers = [];

        foreach ((array) ($entry['containers'] ?? []) as $container) {
            $service = $container['service'] ?? null;
            $name = $container['name'] ?? null;

            if (! is_string($service) || ! is_string($name)
                || preg_match(TOKEN, $service) !== 1 || preg_match(CONTAINER_NAME, $name) !== 1) {
                continue;
            }

            $containers[] = ['service' => $service, 'name' => $name, 'main' => ($container['main'] ?? false) === true];
        }

        if ($containers !== []) {
            $apps[] = ['app' => $app, 'containers' => $containers];
        }
    }

    return $apps;
}

/**
 * @param  array{app: string, containers: list<array{service: string, name: string, main: bool}>}  $app
 * @return array{service: string, name: string, main: bool}
 */
function mainOf(array $app): array
{
    foreach ($app['containers'] as $container) {
        if ($container['main']) {
            return $container;
        }
    }

    return $app['containers'][0];
}

/**
 * Turn `@target` (without the `@`) into one of the caller's containers.
 *
 * `<app>/<service>` always works; `<app>` means its main container; a bare
 * `<service>` only when the caller has exactly one app, because with several
 * it could mean any of them.
 *
 * @param  list<array{app: string, containers: list<array{service: string, name: string, main: bool}>}>  $apps
 * @return array{app: string, container: array{service: string, name: string, main: bool}}|null
 */
function resolve(string $target, array $apps): ?array
{
    [$appName, $service] = str_contains($target, '/')
        ? explode('/', $target, 2)
        : [$target, null];

    if (preg_match(TOKEN, $appName) !== 1 || ($service !== null && preg_match(TOKEN, $service) !== 1)) {
        return null;
    }

    if ($service === null && count($apps) === 1) {
        foreach ($apps[0]['containers'] as $container) {
            if ($container['service'] === $appName) {
                return ['app' => $apps[0]['app'], 'container' => $container];
            }
        }
    }

    foreach ($apps as $app) {
        if ($app['app'] !== $appName) {
            continue;
        }

        if ($service === null) {
            return ['app' => $app['app'], 'container' => mainOf($app)];
        }

        foreach ($app['containers'] as $container) {
            if ($container['service'] === $service) {
                return ['app' => $app['app'], 'container' => $container];
            }
        }
    }

    return null;
}

/**
 * @param  list<array{app: string, containers: list<array{service: string, name: string, main: bool}>}>  $apps
 */
function listing(array $apps): string
{
    $lines = [];

    foreach ($apps as $app) {
        $lines[] = $app['app'];

        foreach ($app['containers'] as $container) {
            $lines[] = '  '.$container['service'].($container['main'] ? '  (main)' : '');
        }
    }

    return implode("\n", $lines)."\n";
}

/**
 * What to do for this caller and this command string. Pure: no docker, no I/O.
 *
 * @param  array<mixed>  $map
 * @return array{action: string, exit?: int, message?: string, app?: string, container?: string, command?: string, tail?: int, follow?: bool}
 */
function plan(string $user, array $map, string $command): array
{
    $apps = appsOf($user, $map);

    if ($apps === []) {
        return ['action' => 'refuse', 'exit' => 1, 'message' => "No Docker app on this server belongs to {$user}.\n"];
    }

    $trimmed = trim($command);

    // sftp and legacy scp arrive as commands too, because ForceCommand catches
    // subsystems. File transfer is not what container login is for.
    $first = strtok($trimmed, " \t") ?: '';

    if ($first === 'internal-sftp' || basename($first) === 'sftp-server'
        || ($first === 'scp' && preg_match('/(^|\s)-[a-zA-Z]*[tf]/', substr($trimmed, 3)) === 1)) {
        return ['action' => 'refuse', 'exit' => 1, 'message' => "File transfer is not available for container login.\n"];
    }

    $default = function () use ($apps): ?array {
        return count($apps) === 1 ? ['app' => $apps[0]['app'], 'container' => mainOf($apps[0])] : null;
    };

    $pick = "{$user} owns more than one Docker app. Pick one with @<app>, for example: ssh -t {$user}@<server> @{$apps[0]['app']}\n\n".listing($apps);

    if ($trimmed === '' || $trimmed[0] !== '@') {
        $target = $default();

        if ($target === null) {
            return ['action' => 'refuse', 'exit' => 2, 'message' => $pick];
        }

        return $trimmed === ''
            ? ['action' => 'shell', 'app' => $target['app'], 'container' => $target['container']['name']]
            : ['action' => 'exec', 'app' => $target['app'], 'container' => $target['container']['name'], 'command' => $trimmed];
    }

    $token = (string) strtok($trimmed, " \t");
    $rest = ltrim(substr($trimmed, strlen($token)));

    if ($token === '@list') {
        return ['action' => 'list', 'message' => listing($apps)];
    }

    if ($token === '@logs') {
        return logsPlan($apps, $rest, $default, $pick);
    }

    $target = resolve(substr($token, 1), $apps);

    if ($target === null) {
        return ['action' => 'refuse', 'exit' => 1, 'message' => NOT_YOURS."\n"];
    }

    return $rest === ''
        ? ['action' => 'shell', 'app' => $target['app'], 'container' => $target['container']['name']]
        : ['action' => 'exec', 'app' => $target['app'], 'container' => $target['container']['name'], 'command' => $rest];
}

/**
 * `@logs [<target>] [--tail N] [--follow|-f]`, in any order.
 *
 * @param  list<array{app: string, containers: list<array{service: string, name: string, main: bool}>}>  $apps
 * @return array{action: string, exit?: int, message?: string, app?: string, container?: string, tail?: int, follow?: bool}
 */
function logsPlan(array $apps, string $rest, callable $default, string $pick): array
{
    $words = $rest === '' ? [] : (preg_split('/\s+/', $rest) ?: []);
    $usage = "Usage: @logs [<service>|<app>|<app>/<service>] [--tail N] [--follow]\n";

    $tail = DEFAULT_TAIL;
    $follow = false;
    $targetName = null;

    for ($i = 0; $i < count($words); $i++) {
        $word = $words[$i];

        if ($word === '--follow' || $word === '-f') {
            $follow = true;
        } elseif ($word === '--tail' || $word === '-n') {
            $value = $words[++$i] ?? '';

            if (preg_match('/^\d{1,6}$/D', $value) !== 1 || (int) $value > MAX_TAIL) {
                return ['action' => 'refuse', 'exit' => 2, 'message' => $usage];
            }

            $tail = (int) $value;
        } elseif ($targetName === null && $word !== '' && $word[0] !== '-') {
            $targetName = ltrim($word, '@');
        } else {
            return ['action' => 'refuse', 'exit' => 2, 'message' => $usage];
        }
    }

    $target = $targetName === null ? $default() : resolve($targetName, $apps);

    if ($target === null) {
        return $targetName === null
            ? ['action' => 'refuse', 'exit' => 2, 'message' => $pick]
            : ['action' => 'refuse', 'exit' => 1, 'message' => NOT_YOURS."\n"];
    }

    return ['action' => 'logs', 'app' => $target['app'], 'container' => $target['container']['name'], 'tail' => $tail, 'follow' => $follow];
}

/**
 * The docker argv for a plan. Every option here is fixed; the only values that
 * vary are the container name (from the map, re-checked above), the tail count
 * (digits only) and the `sh -c` string, which docker passes to the container as
 * a single argument.
 *
 * @param  array{action: string, container?: string, command?: string, tail?: int, follow?: bool}  $plan
 * @return list<string>
 */
function argv(string $docker, array $plan, bool $tty): array
{
    $name = (string) $plan['container'];

    if ($plan['action'] === 'logs') {
        return array_values(array_filter(
            [$docker, 'logs', '--tail', (string) $plan['tail'], $plan['follow'] ? '-f' : null, $name],
            fn ($part) => $part !== null,
        ));
    }

    $exec = $tty ? [$docker, 'exec', '-i', '-t', $name] : [$docker, 'exec', '-i', $name];

    return [...$exec, 'sh', '-c', $plan['action'] === 'shell' ? SHELL : (string) $plan['command']];
}

/**
 * Why this container must not be entered, or null when it may.
 *
 * @param  array<mixed>  $inspect  one element of `docker inspect`
 */
function unsafe(array $inspect): ?string
{
    if (($inspect['HostConfig']['Privileged'] ?? false) === true) {
        return 'This container runs privileged, so a shell in it is a shell on the server. It is not opened from here.';
    }

    $paths = [];

    foreach ((array) ($inspect['Mounts'] ?? []) as $mount) {
        $paths[] = (string) ($mount['Source'] ?? '');
        $paths[] = (string) ($mount['Destination'] ?? '');
    }

    foreach ((array) ($inspect['HostConfig']['Binds'] ?? []) as $bind) {
        $paths = [...$paths, ...explode(':', (string) $bind)];
    }

    foreach ($paths as $path) {
        if (basename($path) === 'docker.sock') {
            return 'This container holds the Docker socket, so a shell in it controls the server. It is not opened from here.';
        }
    }

    if (($inspect['State']['Running'] ?? false) !== true) {
        return 'This container is not running.';
    }

    return null;
}

/** @return array<string, string> */
function environment(): array
{
    return ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin', 'HOME' => '/root', 'LANG' => 'C.UTF-8'];
}

function audit(string $user, string $app, string $container, string $action): void
{
    openlog('app-shell', LOG_PID, LOG_AUTH);
    syslog(LOG_INFO, "user={$user} app={$app} container={$container} action={$action}");
    closelog();
}

/** @param list<string> $argv */
function passthrough(array $argv): int
{
    $process = proc_open($argv, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, '/', environment());

    return is_resource($process) ? proc_close($process) : 1;
}

/** @return array<mixed>|null */
function inspect(string $docker, string $name): ?array
{
    $process = proc_open(
        [$docker, 'inspect', '--type', 'container', $name],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        '/',
        environment(),
    );

    if (! is_resource($process)) {
        return null;
    }

    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    if (proc_close($process) !== 0) {
        return null;
    }

    $decoded = json_decode($output, true);

    return is_array($decoded) && is_array($decoded[0] ?? null) ? $decoded[0] : null;
}

/** @param list<string> $args */
function main(array $args): int
{
    $user = (string) getenv('SUDO_USER');

    if (preg_match(USERNAME, $user) !== 1 || $user === 'root' || count($args) > 2) {
        fwrite(STDERR, "Container login only runs from an app user's SSH login.\n");

        return 1;
    }

    $raw = is_file(MAP) ? file_get_contents(MAP) : false;
    $map = is_string($raw) ? json_decode($raw, true) : null;

    if (! is_array($map)) {
        fwrite(STDERR, "Container login is not set up on this server yet.\n");

        return 1;
    }

    $plan = plan($user, $map, (string) ($args[1] ?? ''));

    if ($plan['action'] === 'list') {
        fwrite(STDOUT, (string) $plan['message']);

        return 0;
    }

    if ($plan['action'] === 'refuse') {
        audit($user, '-', '-', 'refused');
        fwrite(STDERR, (string) $plan['message']);

        return (int) $plan['exit'];
    }

    $docker = null;

    foreach (DOCKER as $candidate) {
        if (is_executable($candidate)) {
            $docker = $candidate;
            break;
        }
    }

    $inspect = $docker === null ? null : inspect($docker, (string) $plan['container']);
    $reason = $inspect === null ? 'This container is not running.' : unsafe($inspect);

    if ($reason !== null || $docker === null) {
        audit($user, (string) $plan['app'], (string) $plan['container'], 'refused');
        fwrite(STDERR, $reason."\n");

        return 1;
    }

    audit($user, (string) $plan['app'], (string) $plan['container'], (string) $plan['action']);

    return passthrough(argv($docker, $plan, stream_isatty(STDIN)));
}

if (! \defined('PANEL_APP_SHELL_LIBRARY')) {
    exit(main($argv));
}
