<?php

namespace App\Services\Server\Capabilities;

use App\Models\ServerCapability;
use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\File;

/**
 * The single source of truth for "what is this server, and what can it run".
 *
 * Normally the installation script writes the row, so reads are just a
 * database lookup — no probing on the request path. When the row is missing
 * (a server migrated in from another panel) the first caller detects once and
 * saves it, so no feature ever sees an empty answer and nothing has to
 * remember to populate it.
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
        'lemp' => ['web_server' => 'nginx', 'capabilities' => ['php' => true, 'node' => false]],
        'lamp' => ['web_server' => 'apache', 'capabilities' => ['php' => true, 'node' => false]],
        'ols' => ['web_server' => 'openlitespeed', 'capabilities' => ['php' => true, 'node' => false]],
        'mern' => ['web_server' => 'nginx', 'capabilities' => ['php' => false, 'node' => true]],
    ];

    public function __construct(private ServerOps $serverOps) {}

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
        // Memoised for the life of the request. This is read by nearly every
        // server feature — and on a box with no record yet it triggers
        // detection, which shells out. Re-detecting per caller turned a
        // single systemctl call into one per consumer.
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
     * The difference from `webServer()` is that this never detects. Callers
     * that only need to pick a driver should not cause a box-wide probe as a
     * side effect of a read — and they have a sane default when the answer is
     * unknown, which is what makes not detecting acceptable here.
     */
    public function recordedWebServer(): ?string
    {
        return $this->current?->web_server
            ?? ServerCapability::query()->value('web_server');
    }

    /**
     * Record what the installer built. Called by `server:record-stack`, which
     * the install script runs; derives the web server and starting capabilities
     * from the stack so only one value is ever authored and they cannot
     * contradict.
     */
    public function recordStack(string $stack): ServerCapability
    {
        $preset = self::STACKS[$stack] ?? null;

        abort_if($preset === null, 422);

        return $this->store([
            'stack' => $stack,
            'web_server' => $preset['web_server'],
            // Detection still wins for the runtimes: the installer may have
            // added more than the preset implies.
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
        $record = $this->current();

        return $this->store([
            'stack' => $record->stack,
            'web_server' => $this->detectWebServer() ?? $record->web_server,
            'capabilities' => $this->detectRuntimes(),
            'source' => $record->source,
        ]);
    }

    private function detectAndStore(): ServerCapability
    {
        return $this->store([
            'stack' => null, // unknown: we did not build this box
            'web_server' => $this->detectWebServer(),
            'capabilities' => $this->detectRuntimes(),
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
     * **A running unit beats a leftover directory.** This used to be a single
     * pass over `server.web_servers` returning the first name whose config
     * directory existed — and a directory is not a web server. `apt remove
     * apache2` leaves /etc/apache2 behind, and apache is listed before
     * openlitespeed, so a box running OpenLiteSpeed with a purged Apache
     * answered "apache". Everything downstream follows this one value, so the
     * panel then wrote Apache vhosts into a directory that no longer existed
     * and site creation failed with `tee: /etc/apache2/sites-available/...:
     * No such file or directory`.
     *
     * So: ask systemd first, which knows what is actually running. The
     * directory pass stays as the fallback — a web server that is installed but
     * stopped is still the one this box uses, and detection has to answer
     * something for the setup screen to be able to say what it found.
     */
    private function detectWebServer(): ?string
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

        // One candidate is not ambiguous, and asking systemd would cost a
        // subprocess to confirm what is already the only answer. That matters
        // beyond speed: this runs on a cold read from anywhere in the panel,
        // and the file browser guarantees it validates a path before touching
        // the server at all. Probing here unconditionally broke that promise.
        if (count($candidates) < 2) {
            return $candidates[0] ?? null;
        }

        // Two config directories, so one of them is a leftover. `apt remove`
        // does not delete /etc/apache2, and apache is listed before
        // openlitespeed — which is how a box running OpenLiteSpeed answered
        // "apache", and every vhost the panel then wrote went to a directory
        // that no longer existed.
        foreach ($candidates as $name) {
            if ($this->unitIsActive($name)) {
                return $name;
            }
        }

        // None of them running: nothing to go on but the order, which is where
        // this started. Returning the first is no worse than before, and the
        // installer's own record beats detection on any box it built.
        return $candidates[0];
    }

    /**
     * Is this web server's systemd unit running right now?
     *
     * The unit name comes from the `services` catalog, which already maps
     * openlitespeed to `lshttpd` — one list, so a rename cannot desync the two.
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
        // Asked of *every* stack rather than the resolved one. On an
        // OpenLiteSpeed box PHP lives outside /etc/php entirely, so globbing
        // for FPM would report a server that has PHP as having none — but the
        // resolved stack is derived from the web server this very method is
        // helping to record, and asking for it here is a cycle. Detection
        // cannot depend on the thing being detected.
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
        return $this->serverOps->run(
            ['which', $binary],
            ['feature' => 'capabilities', 'op' => 'detect', 'binary' => $binary],
        )->ok;
    }
}
