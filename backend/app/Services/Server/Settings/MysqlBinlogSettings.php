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
 * Binary log retention and size for MySQL / MariaDB.
 *
 * Binary logs are what replication reads and what point-in-time recovery
 * replays, and they grow with every write the server takes — forever, unless
 * something expires them. That last part is why this screen exists. A binlog
 * left unbounded does not degrade the database; it fills the disk and takes
 * the whole machine down, which is precisely the outcome this panel is meant
 * to prevent.
 *
 * Deliberately **no on/off switch here.** `log_bin` is a read-only variable:
 * turning binary logging on or off requires restarting the database, which is
 * downtime on a box that is probably serving sites, and that belongs behind a
 * confirmation of its own rather than in a settings form. What this group does
 * is make the logs safe on every server that already has them — which on MySQL
 * 8 is every server, since it defaults to on. MariaDB defaults to off, so
 * there the card reports the state and waits.
 *
 * Same two writes as {@see MysqlSettings}: `SET GLOBAL` for the running server
 * and a drop-in so the value survives a restart. Both variables here are
 * dynamic, so nothing needs restarting to take effect.
 *
 * **Its own drop-in file, and that is not incidental.** `MysqlSettings`
 * rewrites `99-serveravatar.cnf` wholesale on every save. Putting these keys
 * in that file would mean saving the connection limit silently discarded the
 * binlog retention — a setting disappearing because an unrelated form was
 * submitted. Separate files, one owner each.
 */
class MysqlBinlogSettings implements SettingGroup
{
    private const DROP_IN = '99-serveravatar-binlog.cnf';

    /**
     * Variables this group is allowed to set, mapped to their option-file
     * names. `setGlobalVariable()` interpolates the name rather than binding
     * it — a system variable cannot be a placeholder — so the list of names is
     * fixed here and nothing from the request ever reaches it.
     */
    private const MANAGED = [
        'binlog_expire_logs_seconds' => 'binlog_expire_logs_seconds',
        'max_binlog_size' => 'max_binlog_size',
    ];

    public function __construct(
        private DatabaseManager $databases,
        private SqlEngineLocator $locator,
        private ManagedFile $files,
        private ServerOps $serverOps,
    ) {}

    public function key(): string
    {
        return 'mysql_binlog';
    }

    /** Present, not reachable — see {@see MysqlSettings::available()}. */
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
            return ['enabled' => false, 'present' => false, 'reachable' => false];
        }

        if (! $this->locator->reachable($engine)) {
            // Binary logging state is a question only the server can answer,
            // and reporting "disabled" here would be a guess presented as a
            // fact — the same mistake as calling an unreachable engine absent.
            return [
                'engine' => $engine,
                'engine_label' => (string) config("server.databases.engines.{$engine}.label", $engine),
                'present' => true,
                'reachable' => false,
                'enabled' => false,
            ];
        }

        $sql = $this->sqlEngine($engine);
        $enabled = strtoupper((string) $sql->variable('log_bin')) === 'ON';
        $logs = $enabled ? $sql->binaryLogs() : [];

        return [
            'engine' => $engine,
            'engine_label' => (string) config("server.databases.engines.{$engine}.label", $engine),
            // Read-only here on purpose: changing it needs a restart, which
            // this group does not do. The UI explains rather than offers.
            'present' => true,
            'reachable' => true,
            'enabled' => $enabled,
            'format' => $sql->variable('binlog_format'),
            'expire_seconds' => $this->expireSeconds($sql),
            'max_binlog_size' => (int) ($sql->variable('max_binlog_size') ?? 0),
            // The number that actually tells an operator whether retention is
            // working. A count and a total, because "12 files" and "8 GB" are
            // different alarms.
            'log_count' => count($logs),
            'log_bytes' => array_sum(array_column($logs, 'size_bytes')),
            'oldest_log' => $logs[0]['name'] ?? null,
            'configured' => $this->configuredValues(),
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

        if (! $this->locator->reachable($engine)) {
            // 422 naming the cause, not a 500 with a reference: the operator
            // can fix this, and "the engine is unreachable" is the one thing
            // that tells them how.
            throw ValidationException::withMessages([
                'engine' => [__('errors/setting.database_unreachable')],
            ]);
        }

        $values = [
            'binlog_expire_logs_seconds' => (int) $data['expire_seconds'],
            'max_binlog_size' => (int) $data['max_binlog_size'],
        ];

        $lines = "# Managed by the panel — edit via Settings, not by hand.\n[mysqld]\n";
        foreach ($values as $name => $value) {
            $lines .= self::MANAGED[$name].' = '.$value."\n";
        }

        // Persisted first, for the same reason as the connection limit: if
        // this dies half way, a server that comes back with the new retention
        // beats one running a value nothing on disk remembers.
        $write = $this->files->put(
            $this->dropInPath($engine),
            $lines,
            ['feature' => 'setting', 'group' => 'mysql_binlog'],
        );

        if ($write->failed()) {
            throw new SettingOperationException($write->reference);
        }

        $sql = $this->sqlEngine($engine);

        foreach ($values as $name => $value) {
            $sql->setGlobalVariable($name, $value);
        }
    }

    /**
     * Drop logs older than `$days`, now.
     *
     * Retention expires logs on the server's own schedule; this is for the
     * case where the disk is filling today. `PURGE BINARY LOGS` rather than
     * deleting files: mysqld holds them open, so removing them by hand does
     * not free the space and leaves the index naming files that are gone.
     */
    public function purge(int $days): void
    {
        $engine = $this->locator->present();

        if ($engine === null) {
            throw ValidationException::withMessages([
                'engine' => [__('errors/setting.database_absent')],
            ]);
        }

        if (! $this->locator->reachable($engine)) {
            // 422 naming the cause, not a 500 with a reference: the operator
            // can fix this, and "the engine is unreachable" is the one thing
            // that tells them how.
            throw ValidationException::withMessages([
                'engine' => [__('errors/setting.database_unreachable')],
            ]);
        }

        $this->sqlEngine($engine)->purgeBinaryLogs($days);
    }

    /**
     * Retention in seconds, across both spellings.
     *
     * MySQL 8 and MariaDB 10.6+ use `binlog_expire_logs_seconds`. Older
     * MariaDB has only `expire_logs_days`, and a server that answers on the
     * old name and not the new one still has a retention policy — reading only
     * the modern variable would report "no expiry" on a box that expires
     * perfectly well, which is the sort of wrong that gets acted on.
     */
    private function expireSeconds(SqlEngine $sql): int
    {
        $seconds = $sql->variable('binlog_expire_logs_seconds');

        if ($seconds !== null && (int) $seconds > 0) {
            return (int) $seconds;
        }

        $days = $sql->variable('expire_logs_days');

        return $days === null ? 0 : (int) round(((float) $days) * 86400);
    }

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
        $dir = rtrim((string) config("server.databases.engines.{$engine}.config_dir"), '/');

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
    /**
     * @return array<string, int>
     */
    private function configuredValues(): array
    {
        $engine = $this->locator->present();

        if ($engine === null) {
            return [];
        }

        $path = $this->dropInPath($engine);
        $contents = is_readable($path) ? @file_get_contents($path) : false;

        if ($contents === false) {
            return [];
        }

        $configured = [];

        foreach (self::MANAGED as $name => $option) {
            if (preg_match('/^\s*'.preg_quote($option, '/').'\s*=\s*(\d+)/mi', $contents, $m) === 1) {
                $configured[$name] = (int) $m[1];
            }
        }

        return $configured;
    }
}
