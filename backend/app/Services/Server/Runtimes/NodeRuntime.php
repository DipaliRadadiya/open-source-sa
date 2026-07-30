<?php

namespace App\Services\Server\Runtimes;

use App\Contracts\Runtime;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;

/**
 * Node.js versions, managed with fnm.
 *
 * fnm rather than nvm for one reason: nvm works by rewriting `PATH` in an
 * interactive shell, and a systemd unit has neither. A site pinned to Node 18
 * needs `/absolute/path/to/v18/bin/node` written into its `ExecStart=`, and
 * fnm keeps every version at a fixed, readable path. This is the failure other
 * panels ship — nvm works when you SSH in, then the service quietly runs on
 * whatever `node` the system had.
 *
 * fnm is installed system-wide rather than per user: one copy, and every site
 * user can see the versions. Per-user installs multiply the copies and bring
 * back the shell-profile problem for anything not launched from a login shell.
 *
 * A Node that was already on the box — the distro package, NodeSource, however
 * it got there — is reported as `system` and never touched. That is the normal
 * state of a server being migrated in, not an edge case, and clobbering it
 * would break whatever already depends on it.
 */
class NodeRuntime implements Runtime
{
    public function __construct(private ServerOps $serverOps) {}

    public function key(): string
    {
        return 'node';
    }

    public function manager(): string
    {
        if ($this->fnmInstalled()) {
            return 'fnm';
        }

        return $this->system() !== null ? 'system' : 'none';
    }

    /**
     * @return array<int, array{version: string, path: string, is_default: bool, source: string}>
     */
    public function versions(): array
    {
        if (! $this->fnmInstalled()) {
            return [];
        }

        $default = $this->default();

        $versions = collect(preg_split('/\r?\n/', trim($this->fnm(['list'])->output())) ?: [])
            ->map(fn (string $line) => $this->parseVersion($line))
            ->filter()
            ->unique()
            ->map(fn (string $version) => [
                'version' => $version,
                // What goes into a systemd unit. Everything else here is
                // description; this is the part that has to be right.
                'path' => $this->binaryPath($version),
                'is_default' => $version === $default,
                'source' => 'fnm',
            ])
            ->sortByDesc(fn (array $v) => $this->sortKey($v['version']))
            ->values();

        return $versions->all();
    }

    public function default(): ?string
    {
        if (! $this->fnmInstalled()) {
            return null;
        }

        // fnm marks the default alias in its own listing.
        foreach (preg_split('/\r?\n/', trim($this->fnm(['list'])->output())) ?: [] as $line) {
            if (str_contains($line, 'default')) {
                return $this->parseVersion($line);
            }
        }

        return null;
    }

    /**
     * @return array{version: string, path: string}|null
     */
    public function system(): ?array
    {
        $which = $this->serverOps->run(
            ['which', (string) config('server.runtimes.node.system_binary', '/usr/bin/node')],
            ['feature' => 'runtime', 'op' => 'detect_system'],
        );

        $path = trim($which->output());

        if (! $which->ok || $path === '') {
            return null;
        }

        $version = trim($this->serverOps->run(
            [$path, '-v'],
            ['feature' => 'runtime', 'op' => 'system_version'],
        )->output());

        return ['version' => ltrim($version, 'v'), 'path' => $path];
    }

    /**
     * Versions offered in the picker: the LTS and current lines, not the
     * hundreds of patch releases fnm would otherwise list.
     *
     * @return array<int, string>
     */
    public function installable(): array
    {
        if (! $this->fnmInstalled()) {
            return [];
        }

        $remote = collect(preg_split('/\r?\n/', trim($this->fnm(['list-remote'])->output())) ?: [])
            ->map(fn (string $line) => $this->parseVersion($line))
            ->filter();

        // Newest patch of each major — a list of every patch release is a
        // dropdown nobody can use.
        return $remote
            ->groupBy(fn (string $version) => explode('.', $version)[0])
            ->map(fn ($group) => $group->sortByDesc(fn (string $v) => $this->sortKey($v))->first())
            ->sortByDesc(fn (string $v) => $this->sortKey($v))
            ->take((int) config('server.runtimes.node.installable_majors', 6))
            ->values()
            ->all();
    }

    public function fnmInstalled(): bool
    {
        return $this->serverOps->run(
            ['which', $this->fnmBinary()],
            ['feature' => 'runtime', 'op' => 'detect_fnm'],
        )->ok;
    }

    public function installed(string $version): bool
    {
        return collect($this->versions())->contains('version', $version);
    }

    /**
     * The absolute path to a version's binary directory.
     *
     * fnm lays versions out predictably, which is the whole reason it was
     * chosen — this path is what ends up in a systemd unit.
     */
    public function binaryPath(string $version): string
    {
        $dir = rtrim((string) config('server.runtimes.node.dir', '/opt/fnm'), '/');

        return "{$dir}/node-versions/v{$version}/installation/bin/node";
    }

    /**
     * @throws SettingOperationException
     */
    public function install(string $version): void
    {
        $this->must($this->fnm(['install', $version], timeout: (int) config('server.runtimes.node.install_timeout', 900)));
    }

    /**
     * Point bare `node`/`npm`/`npx` at a version.
     *
     * Only the symlinks move. Sites that pinned a version keep the absolute
     * path already written into their unit — changing the server default must
     * not silently migrate a running site to a different Node.
     *
     * @throws SettingOperationException
     */
    public function setDefault(string $version): void
    {
        $this->must($this->fnm(['alias', $version, 'default']));

        $binDir = dirname($this->binaryPath($version));

        foreach (['node', 'npm', 'npx'] as $binary) {
            $this->must($this->serverOps->run(
                ['ln', '-sfn', "{$binDir}/{$binary}", "/usr/local/bin/{$binary}"],
                ['feature' => 'runtime', 'op' => 'link_default', 'version' => $version],
            ));
        }
    }

    /**
     * @throws SettingOperationException
     */
    public function uninstall(string $version): void
    {
        $this->must($this->fnm(['uninstall', $version]));
    }

    /**
     * Update npm inside one version, using that version's own npm — never a
     * global one, which would belong to whichever version happens to be
     * default and update the wrong thing.
     *
     * @throws SettingOperationException
     */
    public function updateNpm(string $version): void
    {
        $npm = dirname($this->binaryPath($version)).'/npm';

        $this->must($this->serverOps->run(
            [$npm, 'install', '-g', 'npm@latest'],
            ['feature' => 'runtime', 'op' => 'update_npm', 'version' => $version],
            timeout: (int) config('server.runtimes.node.install_timeout', 900),
        ));
    }

    /**
     * The npm bundled with one Node version.
     *
     * Read from that version's own npm, not from whatever `npm` is on PATH —
     * which belongs to the default version and would report the wrong number
     * for every other row. Null when it cannot be read, so the UI can say
     * nothing rather than something false.
     */
    public function npmVersion(string $version): ?string
    {
        $result = $this->serverOps->run(
            [dirname($this->binaryPath($version)).'/npm', '-v'],
            ['feature' => 'runtime', 'op' => 'npm_version', 'version' => $version],
        );

        $npm = trim($result->output());

        return $result->ok && $npm !== '' ? $npm : null;
    }

    /**
     * @param  array<int, string>  $args
     */
    private function fnm(array $args, int $timeout = 60): ServerOpsResult
    {
        return $this->serverOps->run(
            [$this->fnmBinary(), '--fnm-dir', (string) config('server.runtimes.node.dir', '/opt/fnm'), ...$args],
            ['feature' => 'runtime', 'op' => 'fnm.'.($args[0] ?? 'run')],
            timeout: $timeout,
        );
    }

    private function fnmBinary(): string
    {
        return (string) config('server.runtimes.node.binary', '/usr/local/bin/fnm');
    }

    private function must(ServerOpsResult $result): void
    {
        if ($result->failed()) {
            throw new SettingOperationException($result->reference);
        }
    }

    private function parseVersion(string $line): ?string
    {
        return preg_match('/v(\d+\.\d+\.\d+)/', $line, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * Sortable form of a semantic version — string comparison puts 9 above 10.
     */
    private function sortKey(string $version): string
    {
        return implode('.', array_map(
            fn (string $part) => str_pad($part, 5, '0', STR_PAD_LEFT),
            explode('.', $version),
        ));
    }
}
