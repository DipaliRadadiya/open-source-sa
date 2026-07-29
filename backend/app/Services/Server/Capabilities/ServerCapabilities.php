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
        $record = ServerCapability::query()->first();

        if ($record !== null) {
            return $record;
        }

        return $this->detectAndStore();
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
     * Record what the installer built. Called by the install script's endpoint
     * (and by tests); derives the web server and starting capabilities from the
     * stack so only one value is ever authored and they cannot contradict.
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
        $record = ServerCapability::query()->first() ?? new ServerCapability;

        $record->fill($attributes + ['verified_at' => now()])->save();

        return $record->refresh();
    }

    /**
     * Whoever owns port 80. Only one can, so the first match wins in the
     * configured order.
     */
    private function detectWebServer(): ?string
    {
        foreach ((array) config('server.web_servers', []) as $name => $paths) {
            foreach ((array) $paths as $path) {
                if (File::isDirectory($path)) {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, bool>
     */
    private function detectRuntimes(): array
    {
        return [
            // Any installed PHP-FPM version means PHP apps can run.
            'php' => $this->hasPhpFpm(),
            'node' => $this->hasBinary((string) config('server.node_binary', 'node')),
        ];
    }

    private function hasPhpFpm(): bool
    {
        return count((array) File::glob(rtrim((string) config('server.php_dir'), '/').'/*/fpm')) > 0;
    }

    private function hasBinary(string $binary): bool
    {
        return $this->serverOps->run(
            ['which', $binary],
            ['feature' => 'capabilities', 'op' => 'detect', 'binary' => $binary],
        )->ok;
    }
}
