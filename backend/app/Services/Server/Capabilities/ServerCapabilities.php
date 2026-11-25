<?php

namespace App\Services\Server\Capabilities;

use App\Models\ServerCapability;
use App\Services\Server\ServerOps;
use App\Support\ProbeCache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * The single source of truth for "what is this server, and what can it run".
 *
 * The installer normally writes the row, so reads are a database lookup. When the row is
 * missing (a server migrated in from another panel) the first caller detects once and saves it.
 */
class ServerCapabilities
{
    private ?ServerCapability $current = null;

    /**
     * Stack presets → what the installer laid down. `mern` maps to nginx
     * because MERN *uses* nginx; it is not a web server of its own.
     *
     * @var array<string, array{web_server: string, capabilities: array<string, bool>}>
     */
    private const STACKS = [
        'lemp' => ['web_server' => 'nginx', 'capabilities' => ['php' => true, 'node' => false, 'serving_profiles' => ['php', 'node', 'static']]],
        'lamp' => ['web_server' => 'apache', 'capabilities' => ['php' => true, 'node' => false, 'serving_profiles' => ['php', 'node', 'static']]],
        'ols' => ['web_server' => 'openlitespeed', 'capabilities' => ['php' => true, 'node' => false, 'serving_profiles' => ['php', 'node', 'static']]],
        'mern' => ['web_server' => 'nginx', 'capabilities' => ['php' => false, 'node' => true, 'serving_profiles' => ['node', 'static']]],
        // lemp/lamp/ols also serve Node sites (n8n, Node-RED, Uptime Kuma, NodeBB), so `node` stays
        // in their profiles.
        // docker hosts containers only. `php => true` is still correct (the panel itself is PHP),
        // but `serving_profiles` says it will not serve PHP *sites*; the rest of the panel reads that.
        'docker' => ['web_server' => 'nginx', 'capabilities' => ['php' => true, 'node' => false, 'serving_profiles' => ['docker']]],
    ];

    /**
     * What a server offers when nothing recorded it.
     *
     * A box migrated in from another panel has `stack = null` and hosts something, so the
     * unknown case is permissive: "hosts nothing" would empty its site-type catalog.
     *
     * @var list<string>
     */
    private const DEFAULT_PROFILES = ['php', 'node', 'static'];

    /** Whether reconcileWebServer() has already run for this request. */
    private bool $reconciled = false;

    public function __construct(private ServerOps $serverOps) {}

    /**
     * Whether this server will serve applications of a given kind.
     *
     * Distinct from `can('php')` (PHP is *installed*, true on every box): this is whether the
     * stack will host PHP *sites*.
     */
    public function hosts(string $profile): bool
    {
        return in_array($profile, $this->servingProfiles(), true);
    }

    /**
     * Whether this server manages database engines of its own.
     *
     * A Docker box says no: an application that needs a database brings it as a container.
     */
    public function managesDatabases(): bool
    {
        return $this->runsHostApplications();
    }

    /**
     * Whether this server runs applications on the host at all, as opposed to only inside containers.
     *
     * A Docker box answers false, so host runtimes and host database engines are hidden. Every
     * other stack, and a server with no recorded stack, answers true.
     *
     * Deliberately NOT `hosts('node')` for the Node question: a LEMP box hosts no Node *sites*,
     * but Node is still a build tool for PHP ones (`npm ci && npm run build`).
     */
    public function runsHostApplications(): bool
    {
        foreach (['php', 'node'] as $profile) {
            if ($this->hosts($profile)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function servingProfiles(): array
    {
        // Reads the recorded row only, not `current()`: that detects and stores when no row
        // exists, which shells out. A middleware reads this on every database request.
        $recorded = ServerCapability::query()->value('capabilities')['serving_profiles'] ?? null;

        // A row written before this key existed is unknown, not empty.
        return is_array($recorded) && $recorded !== []
            ? array_values($recorded)
            : self::DEFAULT_PROFILES;
    }

    /**
     * @return array<int, string>
     */
    public static function stacks(): array
    {
        return array_keys(self::STACKS);
    }

    /**
     * The server record, detected and stored on first use if absent.
     */
    public function current(): ServerCapability
    {
        // Memoised per request: detection shells out, and nearly every server feature reads this.
        return $this->current ??= ServerCapability::query()->first() ?? $this->detectAndStore();
    }

    public function supports(string $capability): bool
    {
        return $this->current()->can($capability);
    }

    public function webServer(): ?string
    {
        return $this->current()->web_server;
    }

    /**
     * The recorded web server, or null if nothing has been recorded yet.
     *
     * Unlike `webServer()` this never detects, so a read does not trigger a box-wide probe.
     */
    public function recordedWebServer(): ?string
    {
        return $this->current?->web_server
            ?? ServerCapability::query()->value('web_server');
    }

    /**
     * The stack the installer recorded, or null if nothing has been recorded.
     *
     * Never detects, like `recordedWebServer()`. Null means "nobody told us", not "not Docker":
     * callers must treat it as the benign case.
     */
    public function recordedStack(): ?string
    {
        return $this->current?->stack
            ?? ServerCapability::query()->value('stack');
    }

    /**
     * Record what the installer built. Called by `server:record-stack` (run by the install script);
     * the web server and starting capabilities derive from the stack so they cannot contradict.
     */
    public function recordStack(string $stack): ServerCapability
    {
        $preset = self::STACKS[$stack] ?? null;

        abort_if($preset === null, 422);

        return $this->store([
            'stack' => $stack,
            'web_server' => $preset['web_server'],
            // Detection wins for the runtimes, but must not win for `serving_profiles`: the merge
            // order keeps the preset's value because `detectRuntimes()` has no such key.
            // `php` is an observation (installed); `serving_profiles` is a decision (will serve PHP sites).
            'capabilities' => array_merge($preset['capabilities'], $this->detectRuntimes()),
            'source' => 'installer',
        ]);
    }

    /**
     * Re-check the runtimes against reality and update the row. Used after the
     * panel installs or removes a runtime, so a newly-installed Node makes the
     * Node app types available immediately.
     */
    public function refresh(): ServerCapability
    {
        ProbeCache::flush();

        $record = $this->current();

        return $this->store([
            'stack' => $record->stack,
            'web_server' => $this->detectWebServer() ?? $record->web_server,
            // Merged over the recorded value, not replacing it: replacing wiped `serving_profiles`.
            'capabilities' => array_merge(
                (array) $record->capabilities,
                $this->detectRuntimes(),
            ),
            'source' => $record->source,
        ]);
    }

    /**
     * Correct a recorded web server that the box contradicts.
     *
     * Deliberately narrow: it corrects only when the recorded web server is **not running and not
     * even installed**, and exactly one supported web server *is* running. Anything less certain is
     * left alone and reported by `panel:doctor`.
     *
     * Returns the corrected name, or null when nothing needed correcting.
     */
    public function reconcileWebServer(): ?string
    {
        // Once per request: `WebServerManager::driver()` calls this on every resolution.
        if ($this->reconciled) {
            return null;
        }

        $this->reconciled = true;

        $record = ServerCapability::query()->first();
        $recorded = $record?->web_server;

        if ($recorded === null) {
            return null;
        }

        // Never overrule the installer: `source: installer` is what was actually built, and a
        // filesystem check is a weaker signal. `panel:doctor` reports a disagreement.
        if ($record->source === 'installer') {
            return null;
        }

        $configured = (array) config('server.web_servers', []);

        // Still installed? A recorded server whose config directory exists may merely be stopped.
        foreach ((array) ($configured[$recorded] ?? []) as $path) {
            if (File::isDirectory($path)) {
                return null;
            }
        }

        $running = [];

        foreach (array_keys($configured) as $name) {
            if ($name !== $recorded && $this->unitIsActive($name)) {
                $running[] = $name;
            }
        }

        if (count($running) !== 1) {
            return null;
        }

        Log::warning('Recorded web server corrected from the running server.', [
            'recorded' => $recorded,
            'running' => $running[0],
        ]);

        $this->store([
            'web_server' => $running[0],
            'source' => 'reconciled',
        ]);

        return $running[0];
    }

    private function detectAndStore(): ServerCapability
    {
        return $this->store([
            'stack' => null, // unknown: we did not build this box
            'web_server' => $this->detectWebServer(),
            // Permissive by default, see DEFAULT_PROFILES.
            'capabilities' => $this->detectRuntimes() + ['serving_profiles' => self::DEFAULT_PROFILES],
            'source' => 'detected',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function store(array $attributes): ServerCapability
    {
        $this->current = null;

        $record = ServerCapability::query()->first() ?? new ServerCapability;

        $record->fill($attributes + ['verified_at' => now()])->save();

        return $record->refresh();
    }

    /**
     * Whoever owns port 80.
     *
     * **A running unit beats a leftover directory:** `apt remove apache2` leaves /etc/apache2
     * behind, so a directory check alone reports "apache" on a box running OpenLiteSpeed. systemd
     * is asked first; the directory pass is the fallback for a web server that is installed but stopped.
     *
     * Public so `WebServerCheck` uses this same logic instead of its own copy.
     */
    public function detectWebServer(): ?string
    {
        $candidates = [];

        foreach ((array) config('server.web_servers', []) as $name => $paths) {
            foreach ((array) $paths as $path) {
                if (File::isDirectory($path)) {
                    $candidates[] = $name;

                    continue 2;
                }
            }
        }

        // One candidate is not ambiguous; skip the systemd subprocess (this runs on cold reads,
        // and the file browser must validate a path before touching the server).
        if (count($candidates) < 2) {
            return $candidates[0] ?? null;
        }

        // Two config directories, so one is a leftover: prefer the running unit.
        foreach ($candidates as $name) {
            if ($this->unitIsActive($name)) {
                return $name;
            }
        }

        // None running: fall back to config order. The installer's own record beats detection anyway.
        return $candidates[0];
    }

    /**
     * Is this web server's systemd unit running right now?
     *
     * The unit name comes from the `services` catalog (openlitespeed maps to `lshttpd`).
     */
    private function unitIsActive(string $name): bool
    {
        $unit = collect((array) config('server.services', []))
            ->firstWhere('key', $name)['unit'] ?? null;

        if (! is_string($unit) || $unit === '') {
            return false;
        }

        return $this->serverOps->run(
            ['systemctl', 'is-active', '--quiet', $unit],
            ['feature' => 'capabilities', 'op' => 'detect_web_server', 'unit' => $unit],
            timeout: 15,
        )->ok;
    }

    /**
     * @return array<string, bool>
     */
    private function detectRuntimes(): array
    {
        return [
            // Any installed PHP version means PHP apps can run.
            'php' => $this->hasPhp(),
            'node' => $this->hasBinary((string) config('server.node_binary', 'node')),
        ];
    }

    private function hasPhp(): bool
    {
        // Asks *every* stack, not the resolved one: the resolved stack derives from the web server
        // this detection is helping to record (a cycle), and OLS keeps PHP outside /etc/php.
        foreach ((array) config('server.php_stacks', []) as $stack) {
            $driver = $stack['driver'] ?? null;

            if ($driver !== null && app($driver)->versions() !== []) {
                return true;
            }
        }

        return false;
    }

    private function hasBinary(string $binary): bool
    {
        // Looked up directly first: install.sh records the stack before the sudoers grant exists,
        // so `sudo -n which` would be refused.
        $candidates = str_contains($binary, '/')
            ? [$binary]
            : array_map(fn (string $dir) => "{$dir}/{$binary}", (array) config('server.binary_search_path'));

        foreach ($candidates as $path) {
            if (is_file($path) && is_executable($path)) {
                return true;
            }
        }

        return $this->serverOps->run(
            ['which', $binary],
            ['feature' => 'capabilities', 'op' => 'detect', 'binary' => $binary],
        )->ok;
    }
}
