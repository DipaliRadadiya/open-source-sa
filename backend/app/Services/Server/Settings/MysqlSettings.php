<?php

namespace App\Services\Server\Settings;

use App\Contracts\SettingGroup;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Databases\SqlEngine;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;

/**
 * `max_connections` for MySQL / MariaDB, applied now and kept across restarts.
 *
 * Two writes, because either alone is half a feature. `SET GLOBAL` changes the
 * running server with no restart and no dropped connections, but dies with the
 * process; a drop-in survives a restart but does nothing until one happens.
 * Doing both means the value is live immediately *and* still there tomorrow,
 * which is what "permanent" has to mean on a screen an operator presses once.
 *
 * The drop-in is a file of our own in the engine's conf.d, never `my.cnf`.
 * That file belongs to the administrator and may well be the one they tuned by
 * hand; a panel that rewrites it is a panel that loses somebody's work. A
 * later-sorting drop-in wins over earlier ones, so `99-` is enough to be
 * authoritative without deleting anything.
 *
 * **The part that actually decides whether this works: the server is allowed
 * to disagree.** MySQL and MariaDB silently reduce `max_connections` when
 * `open_files_limit` cannot support it — each connection needs file
 * descriptors, and systemd's `LimitNOFILE` caps how many the process may have.
 * Ask for 2000 against a low limit and the server quietly settles for less,
 * with no error anywhere. So `apply()` reads the variable back and compares it
 * with what was asked for, and `read()` reports both numbers. The panel is not
 * permitted to say "saved: 2000" while the server says 214.
 *
 * The ceiling is advisory, not enforced. Every connection costs a few hundred
 * KB of per-thread buffers, so a large number on a small box is an OOM waiting
 * for a traffic spike — but it is the operator's server, and a hard cap is the
 * panel deciding it knows their workload better than they do. The form shows
 * the sizing and the recommendation; it does not overrule them.
 */
class MysqlSettings implements SettingGroup
{
    private const DROP_IN = '99-serveravatar.cnf';

    /**
     * Below this the panel's own connection pool cannot reach the database,
     * which is a lockout rather than a setting.
     */
    private const FLOOR = 10;

    public function __construct(
        private DatabaseManager $databases,
        private ManagedFile $files,
        private ServerOps $serverOps,
    ) {}

    public function key(): string
    {
        return 'mysql';
    }

    /**
     * Only when a SQL engine is actually reachable. Detect, don't trust the
     * config list: every box has `mysql` in the catalog, few have it running.
     */
    public function available(): bool
    {
        return $this->engineName() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $engine = $this->engineName();

        if ($engine === null) {
            return ['engine' => null, 'max_connections' => null];
        }

        $status = $this->sqlEngine($engine)->status();
        $effective = (int) ($status['max_connections'] ?? 0);
        $configured = $this->configuredValue();

        return [
            'engine' => $engine,
            'engine_label' => (string) config("server.databases.engines.{$engine}.label", $engine),
            // What the server is running right now.
            'max_connections' => $effective,
            // What our drop-in asks for, when we have written one. Null means
            // the value is the engine's own default or somebody else's file.
            'configured_max_connections' => $configured,
            // The honest headline: these differing is the open_files_limit
            // cap, and the UI says so rather than showing one number twice.
            'capped' => $configured !== null && $effective > 0 && $effective < $configured,
            'open_files_limit' => $this->openFilesLimit(),
            'connections' => (int) ($status['connections'] ?? 0),
            'floor' => self::FLOOR,
            'recommended_max' => $this->recommendedMax(),
            'memory_mb' => $this->memoryMb(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function apply(array $data): void
    {
        $engine = $this->engineName();

        if ($engine === null) {
            throw new SettingOperationException('database.engine_unavailable');
        }

        $requested = (int) $data['max_connections'];

        // Persist first. If the process dies between the two writes, a server
        // that comes back with the new value is a better outcome than one
        // running a value nothing on disk remembers.
        $write = $this->files->put(
            $this->dropInPath($engine),
            "# Managed by the panel — edit via Settings, not by hand.\n"
            ."[mysqld]\n"
            ."max_connections = {$requested}\n",
            ['feature' => 'setting', 'group' => 'mysql'],
        );

        if ($write->failed()) {
            throw new SettingOperationException($write->reference);
        }

        // Then the running server, so no restart is needed. This is the write
        // that can be refused — an engine below the floor would already have
        // been rejected by validation, so a failure here is a real fault.
        $this->sqlEngine($engine)->setGlobalMaxConnections($requested);
    }

    /**
     * The installed SQL engine, or null. MariaDB first: on a box carrying both
     * client packages, the running server is far more often MariaDB, and
     * `available()` is about what answers on the socket rather than what is
     * listed in the catalog.
     */
    private function engineName(): ?string
    {
        foreach (['mariadb', 'mysql'] as $engine) {
            if (! in_array($engine, $this->databases->engineNames(), true)) {
                continue;
            }

            $candidate = $this->databases->engine($engine);

            if ($candidate instanceof SqlEngine && $candidate->available()) {
                return $engine;
            }
        }

        return null;
    }

    /**
     * The engine as its concrete SQL implementation. `engineName()` only ever
     * returns an engine it has already seen answer as one, so this cannot fail
     * for a caller that went through it.
     */
    private function sqlEngine(string $engine): SqlEngine
    {
        $resolved = $this->databases->engine($engine);

        if (! $resolved instanceof SqlEngine) {
            throw new SettingOperationException('database.engine_unavailable');
        }

        return $resolved;
    }

    private function dropInPath(string $engine): string
    {
        $dir = rtrim(
            (string) config("server.databases.engines.{$engine}.config_dir"),
            '/',
        );

        return $dir.'/'.self::DROP_IN;
    }

    /** The value in our own drop-in, or null when we have never written one. */
    private function configuredValue(): ?int
    {
        $engine = $this->engineName();

        if ($engine === null) {
            return null;
        }

        $read = $this->serverOps->run(
            ['cat', $this->dropInPath($engine)],
            ['feature' => 'setting', 'group' => 'mysql', 'op' => 'read_dropin'],
        );

        if ($read->failed()) {
            return null;
        }

        return preg_match('/^\s*max_connections\s*=\s*(\d+)/mi', $read->output(), $m) === 1
            ? (int) $m[1]
            : null;
    }

    /**
     * What the engine is actually allowed in file descriptors. Reported so the
     * cap has a named cause on screen instead of being an unexplained number.
     */
    private function openFilesLimit(): ?int
    {
        $engine = $this->engineName();

        if ($engine === null) {
            return null;
        }

        $limit = $this->sqlEngine($engine)->variable('open_files_limit');

        return $limit === null ? null : (int) $limit;
    }

    private function memoryMb(): ?int
    {
        $meminfo = @file_get_contents('/proc/meminfo');

        if ($meminfo === false) {
            return null;
        }

        return preg_match('/^MemTotal:\s+(\d+) kB/m', $meminfo, $m) === 1
            ? (int) round(((int) $m[1]) / 1024)
            : null;
    }

    /**
     * A sizing recommendation, not a limit.
     *
     * Roughly 50 connections per GB, held to the 50/75/100 shape the ops
     * guidance uses for 1/2/4 GB boxes, and never below the floor. A server
     * with more RAM gets a higher number but not an unbounded one: past a few
     * hundred, connection pooling upstream is the real answer and no value
     * here substitutes for it.
     */
    private function recommendedMax(): ?int
    {
        $memory = $this->memoryMb();

        if ($memory === null) {
            return null;
        }

        return max(self::FLOOR, min(500, (int) round($memory / 1024 * 25) + 25));
    }
}
