<?php

namespace App\Actions\Server\Database;

use App\Exceptions\Server\Database\DatabaseOperationException;
use App\Models\DatabaseConnection;
use App\Services\ActivityLogger;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Databases\Installers\EngineInstallerManager;
use App\Services\Server\Databases\SqlPasswordCheck;
use App\Services\Server\Databases\SqlRootAccess;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Set, change or reset the MySQL/MariaDB root password (v7's "change root
 * password", 2026-10-17; Adarsh: users can change or reset it).
 *
 * The old password is never needed: the panel reaches root as OS root over the
 * socket, or with the stored root password where socket login is gone.
 *
 * What it must not break:
 *
 *  - **MariaDB keeps socket login.** root@localhost there is "password OR
 *    unix_socket", and a plain `IDENTIFIED BY` replaces both with the password
 *    alone — after which OS root, Debian's maintenance and this panel's own
 *    engine installer can no longer get in. So the password slot is set and
 *    unix_socket is kept.
 *  - **MySQL cannot keep it.** It has no "OR"; root goes to
 *    caching_sha2_password and the panel reaches it with the stored password
 *    from then on (SqlRootAccess).
 *  - **Nothing is stored that the server did not accept.** The new password is
 *    proved by signing in with it as the panel's own OS user; if that fails,
 *    root's previous login — exactly as SHOW CREATE USER printed it — is put
 *    back.
 *
 * The panel's own admin account (`panel_…`) is not touched: v7 gave it the
 * same password as root, which is how the two ended up different when one
 * half of a change failed.
 */
class ChangeDatabaseRootPassword
{
    public function __construct(
        private DatabaseManager $manager,
        private EngineInstallerManager $installers,
        private SqlRootAccess $root,
        private SqlPasswordCheck $check,
        private ActivityLogger $activityLogger,
    ) {}

    public function execute(string $engine, string $password): DatabaseConnection
    {
        if (! $this->installers->installer($engine)->installed()) {
            throw ValidationException::withMessages([
                'engine' => [__('errors/database.root_engine_not_installed', ['engine' => $this->label($engine)])],
            ]);
        }

        $lock = Cache::lock("database:root-password:{$engine}", 120);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'engine' => [__('errors/database.root_password_busy')],
            ]);
        }

        try {
            return $this->change($engine, $password);
        } finally {
            $lock->release();
        }
    }

    private function change(string $engine, string $password): DatabaseConnection
    {
        $version = $this->query($engine, 'SELECT VERSION();', 'root_version');

        if ($version->failed()) {
            throw ValidationException::withMessages([
                'engine' => [__('errors/database.root_unreachable', ['engine' => $this->label($engine)])],
            ]);
        }

        $mariadb = stripos($version->output(), 'mariadb') !== false;

        // Root's login as it is now, so a change that does not take can be
        // undone exactly. MySQL prints binary hashes; ask for hex.
        $snapshot = $this->query(
            $engine,
            ($mariadb ? '' : 'SET SESSION print_identified_with_as_hex = ON; ')."SHOW CREATE USER 'root'@'localhost';",
            'root_snapshot',
        );
        $previous = trim($snapshot->output());

        if ($snapshot->failed() || ! str_starts_with($previous, 'CREATE USER')) {
            throw new DatabaseOperationException($snapshot->reference);
        }

        $literal = "'".$this->esc($password)."'";
        $alter = $mariadb
            ? "ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD({$literal}) OR unix_socket; FLUSH PRIVILEGES;"
            : "ALTER USER 'root'@'localhost' IDENTIFIED WITH caching_sha2_password BY {$literal}; FLUSH PRIVILEGES;";

        $applied = $this->query($engine, $alter, 'root_password');

        if ($applied->failed()) {
            // MySQL's validate_password component, when someone installed it.
            // Nothing changed; the message is the server's own rule.
            if (str_contains($applied->errorOutput(), 'does not satisfy the current policy')) {
                throw ValidationException::withMessages([
                    'password' => [__('errors/database.root_password_policy')],
                ]);
            }

            throw new DatabaseOperationException($applied->reference);
        }

        if (! $this->check->works($engine, $password)) {
            $this->restore($engine, $previous, $password);

            throw ValidationException::withMessages([
                'password' => [__('errors/database.root_password_not_applied')],
            ]);
        }

        $connection = $this->manager->connection($engine);
        $connection->root_password = $password;

        // Old or hand-made setups where the panel itself signs in as root.
        if ($connection->username === 'root') {
            $connection->password = $password;
        }

        $connection->save();

        $this->activityLogger->log('database.root_password_changed', $connection, ['engine' => $engine]);

        return $connection->refresh();
    }

    /** Put root's previous login back, reaching it any way that still works. */
    private function restore(string $engine, string $previous, string $newPassword): void
    {
        $sql = preg_replace('/^CREATE USER/', 'ALTER USER', $previous).'; FLUSH PRIVILEGES;';
        $result = $this->root->run($engine, $sql, 'root_password_restore', passwords: [$newPassword]);

        if ($result->failed()) {
            Log::channel('server-ops')->critical('Root password change could not be undone', [
                'engine' => $engine,
                'reference' => $result->reference,
            ]);

            throw new DatabaseOperationException($result->reference);
        }
    }

    private function query(string $engine, string $sql, string $op): ServerOpsResult
    {
        return $this->root->run($engine, $sql, $op, ['--batch', '--skip-column-names', '--raw']);
    }

    private function label(string $engine): string
    {
        return (string) config("server.databases.engines.{$engine}.label", $engine);
    }

    /** Escape a MySQL string literal (as SqlEngine does). */
    private function esc(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
