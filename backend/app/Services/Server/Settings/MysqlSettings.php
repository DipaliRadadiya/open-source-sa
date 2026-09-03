<?php

namespace App\Services\Server\Settings;

use App\Contracts\SettingGroup;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Databases\SqlEngine;
use App\Services\Server\Databases\SqlEngineLocator;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use Illuminate\Validation\ValidationException;

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
        private SqlEngineLocator $locator,
        private ManagedFile $files,
        private ServerOps $serverOps,
    ) {}

    public function key(): string
    {
        return 'mysql';
    }

    /**
     * Present, not reachable.
     *
     * This used to require a successful `SELECT 1`, which meant a box with
     * MariaDB installed and running but whose stored credentials do not work
     * lost the card entirely — and the page then said no engine was installed,
     * which was false. A card that disappears cannot explain itself, so the
     * bar for showing it is that the engine exists; whether the panel can log
     * in is reported inside it.
     */
    public function available(): bool
    {
        return $this->locator->present() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $engine = $this->locator->present();

        if ($engine === null) {
            return ['engine' => null, 'present' => false, 'reachable' => false, 'max_connections' => null];
        }

        $label = (string) config("server.databases.engines.{$engine}.label", $engine);
        // Read before the reachability branch: it comes off our own drop-in
        // and both branches report it.
        $configured = $this->configuredValue();

        // Installed but not answering. Everything below this point needs a
        // working connection, and inventing zeroes for it would report an idle
        // database rather than an unreachable one.
        if (! $this->locator->reachable($engine)) {
            return [
                'engine' => $engine,
                'engine_label' => $label,
                'present' => true,
                'reachable' => false,
                'max_connections' => null,
                // Still worth showing: it comes off our own drop-in, needs no
                // connection, and "what the panel asked for" is the one fact
                // available while the server cannot be asked anything.
                'configured_max_connections' => $configured,
            ];
        }

        $status = $this->sqlEngine($engine)->status();
        $effective = (int) ($status['max_connections'] ?? 0);

        return [
            'engine' => $engine,
            'engine_label' => $label,
            'present' => true,
            'reachable' => true,
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
        $engine = $this->locator->present();

        if ($engine === null) {
            throw ValidationException::withMessages([
                'engine' => [__('errors/setting.database_absent')],
            ]);
        }

        // Refuse rather than half-apply: the drop-in would be written and the
        // running server left alone, so the panel would claim a value that
        // only arrives at the next restart.
        if (! $this->locator->reachable($engine)) {
            // 422 naming the cause, not a 500 with a reference: the operator
            // can fix this, and "the engine is unreachable" is the one thing
            // that tells them how.
            throw ValidationException::withMessages([
                'engine' => [__('errors/setting.database_unreachable')],
            ]);
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
     * The engine as its concrete SQL implementation. Only ever called for an
     * engine the locator has already reported present, so a non-SQL driver
     * here would be a wiring mistake rather than a state this can be in.
     */
    private function sqlEngine(string $engine): SqlEngine
    {
        $resolved = $this->databases->engine($engine);

        if (! $resolved instanceof SqlEngine) {
            throw ValidationException::withMessages([
                'engine' => [__('errors/setting.database_absent')],
            ]);
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

    /**
     * Read our own drop-in directly, not through ServerOps.
     *
     * It is a file we wrote, 0644, in a directory the panel can already list —
     * there is nothing to elevate. Going through ServerOps meant `sudo cat`,
     * which on a panel running as `www-data` is denied; ServerOps then *logs*
     * the denial, and where `storage/logs` is not writable by that account the
     * logging itself throws. `SettingsManager::all()` catches per group and
     * drops the ones that throw, so the card vanished from the API and the
     * page reported no engine — with `available()` returning true the whole
     * time. Reading the file is the operation; a subprocess was never needed
     * for it.
     */
    private function configuredValue(): ?int
    {
        $engine = $this->locator->present();

        if ($engine === null) {
            return null;
        }

        $path = $this->dropInPath($engine);
        $contents = is_readable($path) ? @file_get_contents($path) : false;

        if ($contents === false) {
            return null;
        }

        return preg_match('/^\s*max_connections\s*=\s*(\d+)/mi', $contents, $m) === 1
            ? (int) $m[1]
            : null;
    }

    /**
     * What the engine is actually allowed in file descriptors. Reported so the
     * cap has a named cause on screen instead of being an unexplained number.
     */
    private function openFilesLimit(): ?int
    {
        $engine = $this->locator->present();

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
