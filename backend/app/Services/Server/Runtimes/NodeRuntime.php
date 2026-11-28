<?php

namespace App\Services\Server\Runtimes;

use App\Contracts\Runtime;
use App\Exceptions\Server\Runtime\NodeRemovalException;
use App\Exceptions\Server\Runtime\RuntimeInstallException;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Rules\SupportedNodeVersion;
use App\Services\Runtime\InstallFailureClassifier;
use App\Services\Runtime\LifecycleCatalog;
use App\Services\Runtime\NpmCatalog;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Node.js versions, managed with fnm.
 *
 * fnm rather than nvm: nvm rewrites `PATH` in an interactive shell, which a systemd unit lacks. A
 * site pinned to Node 18 needs `/absolute/path/to/v18/bin/node` in its `ExecStart=`, and fnm keeps
 * every version at a fixed, readable path.
 *
 * fnm is installed system-wide, not per user: one copy, visible to every site user.
 *
 * A Node that was already on the box (distro package, NodeSource) is reported as `system` and
 * never touched: it is the normal state of a server being migrated in, and clobbering it would
 * break whatever depends on it.
 */
class NodeRuntime implements Runtime
{
    private ?string $installableError = null;

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
     * Is this a Node release that exists?
     *
     * Asked before an install: a version checked only for its shape (`99.0.0`) would be queued,
     * fail in fnm and leave an entry behind. The whole release list, not just the newest per major
     * the picker shows: an older patch is a real version someone may need.
     *
     * Null when the list cannot be read (no fnm, no network), so an install
     * is never refused for want of an answer — fnm will say so itself.
     */
    public function releaseExists(string $version): ?bool
    {
        $releases = Cache::remember('node.remote_releases', now()->addHour(), function (): array {
            if (! $this->fnmInstalled()) {
                return [];
            }

            return collect(preg_split('/\r?\n/', trim($this->fnm(['list-remote'])->output())) ?: [])
                ->map(fn (string $line) => $this->parseVersion($line))
                ->filter()
                ->values()
                ->all();
        });

        return $releases === [] ? null : in_array($version, $releases, true);
    }

    /**
     * Why the last installable() came back empty when it was not asked a
     * question it could answer — `unreachable` — or null.
     */
    public function installableError(): ?string
    {
        return $this->installableError;
    }

    /**
     * Versions offered in the picker: the supported lines, not the hundreds
     * of patch releases fnm would otherwise list, and not the dead ones.
     *
     * End-of-life lines are not offered: native modules ship prebuilt binaries per Node ABI and
     * nobody builds them for a buried release (an n8n install on Node 21 died compiling
     * `isolated-vm`). "Dead" is read from {@see LifecycleCatalog} (Node's own
     * `Release/schedule.json`), never inferred from an odd major number.
     *
     * Unknown means kept, not hidden: a box with no egress has never refreshed the catalog, and
     * hiding everything would turn an absent badge into an empty screen.
     *
     * @return array<int, string>
     */
    public function installable(): array
    {
        $this->installableError = null;

        if (! $this->fnmInstalled()) {
            return [];
        }

        $listed = $this->fnm(['list-remote']);

        // A catalogue that could not be fetched (nodejs.org blocked, no egress) must not answer
        // `[]`, the same as "there is nothing to install".
        if ($listed->failed() || trim($listed->output()) === '') {
            $this->installableError = 'unreachable';

            return [];
        }

        $remote = collect(preg_split('/\r?\n/', trim($listed->output())) ?: [])
            ->map(fn (string $line) => $this->parseVersion($line))
            ->filter();

        // One read for the whole list. `LifecycleCatalog::for()` queries the
        // table on every call, and this asks about a dozen versions.
        $lifecycle = $this->lifecycle->all()['node'] ?? [];
        $offerEol = (bool) config('server.runtimes.node.offer_eol', false);

        // Newest patch of each major — a list of every patch release is a
        // dropdown nobody can use.
        return $remote
            ->groupBy(fn (string $version) => $this->line($version))
            ->map(fn ($group) => $group->sortByDesc(fn (string $v) => $this->sortKey($v))->first())
            // Before the take, not after: otherwise dropping dead lines would shrink the picker.
            ->reject(fn (string $version) => ! $offerEol && $this->isEndOfLife($version, $lifecycle))
            ->sortByDesc(fn (string $v) => $this->sortKey($v))
            ->take((int) config('server.runtimes.node.installable_majors', 6))
            ->values()
            ->all();
    }

    /**
     * Has Node stopped supporting this line?
     *
     * False for anything the catalog has no answer about: "we have not been told" is not "it is
     * dead".
     *
     * @param  array<string, array<string, mixed>>  $lifecycle
     */
    private function isEndOfLife(string $version, array $lifecycle): bool
    {
        $line = $this->line($version);

        if (isset($lifecycle[$line]['status'])) {
            return $lifecycle[$line]['status'] === 'eol';
        }

        // Unknown, but older than a line the catalog says is dead: dead too. The schedule does not
        // list the pre-1.0 development lines (0.7, 0.9, 0.11) at all. A line NEWER than everything
        // dead (not yet in the catalog) is still kept.
        foreach ($lifecycle as $known => $entry) {
            if (($entry['status'] ?? null) === 'eol' && version_compare($line, (string) $known, '<')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The release line a version belongs to, as Node's own schedule names it.
     *
     * The major for 4 onwards, but `0.12`, `0.10`, `0.8` before that: the schedule files the
     * pre-1.0 lines by minor, so a lookup by major alone (`0`) finds nothing and reads "keep".
     */
    private function line(string $version): string
    {
        $parts = explode('.', $version);

        return $parts[0] === '0' ? '0.'.($parts[1] ?? '0') : $parts[0];
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
     * The installed version a site with no Node chosen runs on: the default when it is inside the
     * range, else the newest installed one that is. Null when none is.
     *
     * One answer for the form, which refuses the null case, and for the create, which records it.
     */
    public function bestFit(?string $min, ?string $max): ?string
    {
        $fits = fn (?string $version): bool => $version !== null
            && SupportedNodeVersion::admits($min, $max, $version);

        $default = $this->default();

        if ($fits($default)) {
            return $default;
        }

        return collect($this->versions())
            ->pluck('version')
            ->sort(fn (string $a, string $b) => version_compare($b, $a))
            ->first($fits);
    }

    /**
     * The fnm version the panel's own frontend runs on, or null.
     *
     * install.sh writes that binary's absolute path into the unit's `ExecStart=`, so it is read
     * from there rather than assumed to be the default: removing it would stop the panel's screens.
     */
    public function panelVersion(): ?string
    {
        $result = $this->serverOps->run(
            ['systemctl', 'show', '-p', 'ExecStart', '--value', (string) config('panel_update.services.frontend', 'panel-frontend.service')],
            ['feature' => 'runtime', 'op' => 'node_panel_version'],
        );

        return preg_match('#/node-versions/v(\d+\.\d+\.\d+)/#', $result->output(), $match) === 1 ? $match[1] : null;
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
     * The absolute path to a version's `pm2-runtime`.
     *
     * Beside that version's `node`, because PM2 is installed *into* a version rather than once
     * for the machine: every application can pin its own Node, and a global install would be tied
     * to whichever version owns `/usr/local/bin`.
     */
    public function pm2RuntimePath(string $version): string
    {
        return dirname($this->binaryPath($version)).'/pm2-runtime';
    }

    public function pm2Installed(string $version): bool
    {
        return $this->serverOps->probe(
            ['test', '-x', $this->pm2RuntimePath($version)],
            ['feature' => 'runtime', 'op' => 'pm2_installed', 'version' => $version],
        )->ok;
    }

    /**
     * Install PM2 into one Node version.
     *
     * Pinned, not `@latest`, so servers provisioned apart get the same major and a re-run never
     * silently upgrades PM2 under running applications.
     *
     * Idempotent: a version that already has it is left alone, so this can be
     * called on every switch to a version without paying for a reinstall.
     *
     * @throws SettingOperationException
     */
    public function installPm2(string $version, ?callable $onOutput = null): void
    {
        if ($this->pm2Installed($version)) {
            return;
        }

        $binDir = dirname($this->binaryPath($version));
        $pinned = 'pm2@'.config('server.runtimes.node.pm2_version', '6.0.13');

        $this->must($this->serverOps->run(
            [$binDir.'/npm', 'install', '-g', $pinned],
            ['feature' => 'runtime', 'op' => 'pm2_install', 'version' => $version],
            timeout: (int) config('server.runtimes.node.install_timeout', 900),
            onOutput: $onOutput,
        ));

        // `npm -g` writes into that version's own prefix, so the binary should now be beside its
        // node. Verified, because a unit pointing at a missing `pm2-runtime` fails at start with an
        // error that names the path and not the install that never happened.
        $this->must($this->serverOps->run(
            ['test', '-x', $this->pm2RuntimePath($version)],
            ['feature' => 'runtime', 'op' => 'pm2_verify', 'version' => $version],
        ));
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

        $this->adoptOwnership($version);
    }

    /**
     * Give a version's files to whoever owns the fnm directory.
     *
     * install.sh hands /opt/fnm to the panel account, but `fnm` itself runs elevated, so versions
     * installed later come out root-owned and {@see updateNpm()}, which runs as the panel account,
     * fails with EACCES on them.
     *
     * The owner is read off the fnm directory rather than configured. A root-owned fnm directory
     * means there is nothing to hand over.
     */
    public function adoptOwnership(string $version): void
    {
        $dir = rtrim((string) config('server.runtimes.node.dir', '/opt/fnm'), '/');

        $owner = trim($this->serverOps->run(
            ['stat', '-c', '%U:%G', $dir],
            ['feature' => 'runtime', 'op' => 'fnm_owner'],
        )->output());

        if ($owner === '' || str_starts_with($owner, 'root:')) {
            return;
        }

        $this->serverOps->run(
            ['chown', '-R', $owner, "{$dir}/node-versions/v{$version}"],
            ['feature' => 'runtime', 'op' => 'adopt_node_version', 'version' => $version],
            timeout: 300,
        );
    }

    /**
     * Make bare `node`/`npm`/`npx` point at the default version, if it is set.
     *
     * install.sh only sets fnm's own alias, so a fresh server would have a default version and no
     * `node` on anyone's PATH (SSH user, cron, installers run without a pinned version).
     * Idempotent: `ln -sfn` over a correct link changes nothing.
     *
     * @throws SettingOperationException
     */
    public function linkDefault(): ?string
    {
        $default = $this->default();

        if ($default === null) {
            return null;
        }

        // Not in front of a Node the server already had: /usr/local/bin comes first on PATH, so a
        // link would silently move every site running on the existing Node (e.g. a v7 migration's
        // /usr/bin/node) to this version. Links this panel made before are taken back; anything
        // else in /usr/local/bin is left alone. Choosing a default on the Node screen
        // ({@see setDefault()}) still links, because that is the user deciding.
        if ($this->systemNodePresent()) {
            $this->unlinkOwnLinks();

            return null;
        }

        $this->assertBinaries($default);
        $this->linkBinaries($default);

        return $default;
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

    /**
     * A Node installed outside fnm — v7's nodesource package, or the distro's.
     */
    public function systemNodePresent(): bool
    {
        return $this->serverOps->probe(
            ['test', '-x', (string) config('server.runtimes.node.outside_binary', '/usr/bin/node')],
            ['feature' => 'runtime', 'op' => 'system_node_check'],
        )->ok;
    }

    /**
     * Remove node/npm/npx from /usr/local/bin only where they are this
     * panel's links into the fnm directory.
     */
    private function unlinkOwnLinks(): void
    {
        $dir = rtrim((string) config('server.runtimes.node.dir', '/opt/fnm'), '/');

        foreach (['node', 'npm', 'npx'] as $binary) {
            $this->warnOnFailure($this->serverOps->run(
                ['find', '/usr/local/bin', '-maxdepth', '1', '-name', $binary, '-type', 'l', '-lname', "{$dir}/*", '-delete'],
                ['feature' => 'runtime', 'op' => 'unlink_default', 'binary' => $binary],
            ));
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
     * @throws NodeRemovalException with fnm's own reason
     */
    public function uninstall(string $version): void
    {
        $result = $this->fnm(['uninstall', $version]);

        if ($result->failed()) {
            throw new NodeRemovalException($version, $result->reference, self::lastLine($result->errorOutput()."\n".$result->output()));
        }
    }

    private static function lastLine(string $text): ?string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $text) ?: [])));

        return $lines === [] ? null : mb_strimwidth((string) end($lines), 0, 300, '…');
    }

    /**
     * Update npm inside one version, using that version's own npm — never a
     * global one, which would belong to whichever version happens to be
     * default and update the wrong thing.
     *
     * Installs the newest npm *this* Node version can run, which the caller resolves and passes in:
     * npm's newest release routinely excludes Node lines the panel still installs, so `@latest` on
     * an older Node replaces a working npm with one that cannot start.
     *
     * The version is a **required argument, and there is no `npm@latest` fallback**: an empty
     * catalog is the ordinary state of a server whose catalog was never filled. Resolving the
     * target is {@see NpmCatalog::resolveTarget()}'s job, and not knowing is that caller's cue to
     * refuse, not to guess.
     *
     * @throws SettingOperationException
     */
    public function updateNpm(string $version, string $target): void
    {
        $spec = "npm@{$target}";

        // PATH pinned as in {@see npmVersion()}: npm is a Node script (`#!/usr/bin/env node`) and
        // needs `node` on PATH even when run by absolute path. This version's own bin dir, not a
        // default: borrowing another version's node is how the wrong thing gets updated.
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

    /**
     * A release, never a pre-release. Node publishes alphas and RCs in the same list
     * (`v27.0.0-alpha.0.1.1`); reading only the leading number would turn that into `27.0.0`, a
     * version that does not exist.
     */
    private function parseVersion(string $line): ?string
    {
        return preg_match('/v(\d+\.\d+\.\d+)(?=\s|$)/', $line, $matches) === 1 ? $matches[1] : null;
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
