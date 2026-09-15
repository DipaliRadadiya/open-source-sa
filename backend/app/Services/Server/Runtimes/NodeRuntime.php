<?php

namespace App\Services\Server\Runtimes;

use App\Contracts\Runtime;
use App\Exceptions\Server\Runtime\RuntimeInstallException;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Services\Runtime\InstallFailureClassifier;
use App\Services\Runtime\LifecycleCatalog;
use App\Services\Runtime\NpmCatalog;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\Log;

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
    public function __construct(
        private ServerOps $serverOps,
        private InstallFailureClassifier $classifier,
        private NpmCatalog $npm,
        private LifecycleCatalog $lifecycle,
    ) {}

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
     * Versions offered in the picker: the supported lines, not the hundreds
     * of patch releases fnm would otherwise list, and not the dead ones.
     *
     * 🔴 Dead lines were offered until 2026-09-15, and offering one is a trap
     * rather than a choice. A user picked Node 21 — end of life since June
     * 2024 — for a one-click n8n site, and the install died compiling
     * `isolated-vm`: native modules ship prebuilt binaries per Node ABI, and
     * nobody builds them for a release the project has buried. The version
     * carried an EOL badge in the list it was offered from, which was not
     * enough. A version nobody should install does not belong in the list of
     * versions to install.
     *
     * "Dead" is read from {@see LifecycleCatalog} — Node's own
     * `Release/schedule.json` — never inferred from an odd major number. That
     * is the rule the catalog's own docblock states, and it is right: the
     * convention is a convention, and a panel that hard-codes it would be
     * confidently wrong the day it changed.
     *
     * Unknown means kept, not hidden. A box with no egress has never refreshed
     * the catalog, and answering "no versions to install" there would turn an
     * absent badge into an empty screen.
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

        // One read for the whole list. `LifecycleCatalog::for()` queries the
        // table on every call, and this asks about a dozen versions.
        $lifecycle = $this->lifecycle->all()['node'] ?? [];
        $offerEol = (bool) config('server.runtimes.node.offer_eol', false);

        // Newest patch of each major — a list of every patch release is a
        // dropdown nobody can use.
        return $remote
            ->groupBy(fn (string $version) => explode('.', $version)[0])
            ->map(fn ($group) => $group->sortByDesc(fn (string $v) => $this->sortKey($v))->first())
            // Before the take, not after: dropping three dead lines out of a
            // list of six would otherwise leave three, and the picker would
            // get shorter every time a Node release died.
            ->reject(fn (string $version) => ! $offerEol && $this->isEndOfLife($version, $lifecycle))
            ->sortByDesc(fn (string $v) => $this->sortKey($v))
            ->take((int) config('server.runtimes.node.installable_majors', 6))
            ->values()
            ->all();
    }

    /**
     * Has Node stopped supporting this line?
     *
     * False for anything the catalog has no answer about, which is the honest
     * reading: "we have not been told" is not "it is dead".
     *
     * @param  array<string, array<string, mixed>>  $lifecycle
     */
    private function isEndOfLife(string $version, array $lifecycle): bool
    {
        $major = explode('.', $version)[0];

        return ($lifecycle[$major]['status'] ?? null) === 'eol';
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
    public function install(string $version, ?callable $onOutput = null): void
    {
        $result = $this->fnm(
            ['install', $version],
            timeout: (int) config('server.runtimes.node.install_timeout', 900),
            onOutput: $onOutput,
        );

        // Classified here, where fnm's output still exists — past this point
        // only the reason code travels.
        if ($result->failed()) {
            throw new RuntimeInstallException(
                $result->reference,
                $this->classifier->classify('node', $result),
            );
        }
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
        $previous = $this->default();

        // Refuse before changing the alias if an incomplete fnm install left
        // one of the binaries absent.
        $this->assertBinaries($version);

        try {
            $this->must($this->fnm(['alias', $version, 'default']));
            $this->linkBinaries($version);
        } catch (SettingOperationException $e) {
            $this->restoreDefault($previous, $version);

            throw $e;
        }
    }

    private function assertBinaries(string $version): void
    {
        $binDir = dirname($this->binaryPath($version));

        foreach (['node', 'npm', 'npx'] as $binary) {
            $this->must($this->serverOps->run(
                ['test', '-x', "{$binDir}/{$binary}"],
                ['feature' => 'runtime', 'op' => 'verify_default_binary', 'version' => $version, 'binary' => $binary],
            ));
        }
    }

    private function linkBinaries(string $version): void
    {
        $binDir = dirname($this->binaryPath($version));

        foreach (['node', 'npm', 'npx'] as $binary) {
            $this->must($this->serverOps->run(
                ['ln', '-sfn', "{$binDir}/{$binary}", "/usr/local/bin/{$binary}"],
                ['feature' => 'runtime', 'op' => 'link_default', 'version' => $version],
            ));
        }
    }

    private function restoreDefault(?string $previous, string $attempted): void
    {
        if ($previous === null) {
            // There was no previous default to restore. Remove any links made
            // by this failed first selection rather than leaving a mixed set.
            foreach (['node', 'npm', 'npx'] as $binary) {
                $this->warnOnFailure($this->serverOps->run(
                    ['rm', '-f', "/usr/local/bin/{$binary}"],
                    ['feature' => 'runtime', 'op' => 'rollback_default_link', 'version' => $attempted, 'binary' => $binary],
                ));
            }

            return;
        }

        $this->warnOnFailure($this->fnm(['alias', $previous, 'default']));

        try {
            $this->linkBinaries($previous);
        } catch (SettingOperationException $e) {
            Log::warning('node default rollback failed', ['reference' => $e->reference]);
        }
    }

    private function warnOnFailure(ServerOpsResult $result): void
    {
        if ($result->failed()) {
            Log::warning('node default rollback failed', ['reference' => $result->reference]);
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
     * Installs the newest npm *this* Node version can run, which the caller
     * resolves and passes in. npm declares `engines.node` and its newest
     * release routinely excludes Node lines the panel still installs — npm 12
     * needs `^22.22.2 || ^24.15.0 || >=26.0.0`, so `@latest` on a Node 20 box
     * replaces a working npm with one that cannot start.
     *
     * The version is a **required argument, and `npm@latest` is gone**. This
     * used to fall back to it whenever the catalog could not answer, on the
     * grounds that a box with no egress was then no worse off than before the
     * catalog existed — but an empty catalog is not a rare offline box, it is
     * the ordinary state of every server whose catalog has never been filled,
     * and on those the fallback is the whole bug: the button that exists to
     * keep npm current was the one breaking it. Resolving the target is now
     * {@see NpmCatalog::resolveTarget()}'s job, and not knowing is that
     * caller's cue to refuse rather than this one's cue to guess.
     *
     * @throws SettingOperationException
     */
    public function updateNpm(string $version, string $target): void
    {
        $spec = "npm@{$target}";

        // PATH pinned for the same reason {@see npmVersion()} pins it, and it
        // was missing here: npm is a Node script (`#!/usr/bin/env node`), so it
        // needs `node` on PATH even when run by absolute path. Without it this
        // died in three milliseconds with "/usr/bin/env: 'node': No such file
        // or directory" — an error about node, from a command about npm, on a
        // box where both are installed.
        //
        // This version's own bin dir, not a default: the point of the method is
        // to update npm inside one version, and borrowing another version's
        // node to do it is how the wrong thing gets updated.
        $binDir = dirname($this->binaryPath($version));

        $this->must($this->serverOps->run(
            ["{$binDir}/npm", 'install', '-g', $spec],
            ['feature' => 'runtime', 'op' => 'update_npm', 'version' => $version],
            timeout: (int) config('server.runtimes.node.install_timeout', 900),
            env: ['PATH' => "{$binDir}:/usr/local/bin:/usr/bin:/bin"],
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
        // npm is a Node script (`#!/usr/bin/env node`), so it needs `node` on
        // PATH even when run by absolute path. On a fresh box no default is
        // linked into /usr/local/bin, so point PATH at this version's own bin
        // dir — which is also the correct node for this version's npm.
        $binDir = dirname($this->binaryPath($version));

        $result = $this->serverOps->run(
            ["{$binDir}/npm", '-v'],
            ['feature' => 'runtime', 'op' => 'npm_version', 'version' => $version],
            env: ['PATH' => "{$binDir}:/usr/local/bin:/usr/bin:/bin"],
        );

        $npm = trim($result->output());

        return $result->ok && $npm !== '' ? $npm : null;
    }

    /**
     * @param  array<int, string>  $args
     */
    private function fnm(array $args, int $timeout = 60, ?callable $onOutput = null): ServerOpsResult
    {
        return $this->serverOps->run(
            [$this->fnmBinary(), '--fnm-dir', (string) config('server.runtimes.node.dir', '/opt/fnm'), ...$args],
            ['feature' => 'runtime', 'op' => 'fnm.'.($args[0] ?? 'run')],
            timeout: $timeout,
            onOutput: $onOutput,
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
