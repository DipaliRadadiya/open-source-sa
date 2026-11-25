<?php

namespace App\Actions\Server\Database;

use App\Exceptions\Server\Database\DatabaseOperationException;
use App\Exceptions\Server\Database\RootAccessRefusedException;
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
 * Set, change or reset the MySQL/MariaDB root password.
 *
 * The old password is normally not needed: the panel reaches root as OS root over the socket, or
 * with the stored root password. Where both fail (e.g. a server moved from v7), the user may give
 * root's current password. It is tried last, only for this change, and never stored or logged:
 * it travels in a 0600 option file (SqlRootAccess), never on argv or in SQL. Without it the answer
 * is `root_unreachable`, the dialog's cue to ask for it.
 *
 * What it must not break:
 *
 *  - **MariaDB keeps socket login.** root@localhost is "password OR unix_socket"; a plain
 *    `IDENTIFIED BY` drops the socket login and locks out OS root, Debian maintenance and the
 *    engine installer. So the password is set and unix_socket is kept.
 *  - **MySQL cannot keep it.** No "OR": root goes to caching_sha2_password and the panel uses the
 *    stored password from then on (SqlRootAccess).
 *  - **Nothing is stored that the server did not accept.** The new password is proved by signing
 *    in with it as the panel's OS user; if that fails, root's previous login (as SHOW CREATE USER
 *    printed it) is put back.
 *
 * The panel's own admin account (`panel_...`) is not touched.
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

    /**
     * @param  string|null  $currentPassword  root's current password, tried only
     *                                        when socket login and the stored
     *                                        password both fail
     */
    public function execute(string $engine, string $password, ?string $currentPassword = null): DatabaseConnection
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
            return $this->change($engine, $password, $currentPassword === null || $currentPassword === '' ? [] : [$currentPassword]);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<string>  $current  root's current password, when the user gave it
     */
    private function change(string $engine, string $password, array $current): DatabaseConnection
    {
        $version = $this->query($engine, 'SELECT VERSION();', 'root_version', $current);

        if ($version->failed()) {
            // Not a refusal (server down or client missing): no password would help.
            if (! $this->refused($version)) {
                throw ValidationException::withMessages([
                    'engine' => [__('errors/database.root_server_unreachable', ['engine' => $this->label($engine)])],
                ]);
            }

            throw $current === []
                ? RootAccessRefusedException::needsCurrentPassword($this->label($engine))
                : RootAccessRefusedException::wrongCurrentPassword();
        }

        $mariadb = stripos($version->output(), 'mariadb') !== false;

        // Root's login as it is now, so a failed change can be undone exactly. MySQL prints binary
        // hashes; ask for hex.
        $snapshot = $this->query(
            $engine,
            ($mariadb ? '' : 'SET SESSION print_identified_with_as_hex = ON; ')."SHOW CREATE USER 'root'@'localhost';",
            'root_snapshot',
            $current,
        );
        $previous = trim($snapshot->output());

        if ($snapshot->failed() || ! str_starts_with($previous, 'CREATE USER')) {
            throw new DatabaseOperationException($snapshot->reference);
        }

        $literal = "'".$this->esc($password)."'";
        $alter = $mariadb
            ? "ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD({$literal}) OR unix_socket; FLUSH PRIVILEGES;"
            : "ALTER USER 'root'@'localhost' IDENTIFIED WITH caching_sha2_password BY {$literal}; FLUSH PRIVILEGES;";

        $applied = $this->query($engine, $alter, 'root_password', $current);

        if ($applied->failed()) {
            // MySQL's validate_password component, if installed. Nothing changed.
            if (str_contains($applied->errorOutput(), 'does not satisfy the current policy')) {
                throw ValidationException::withMessages([
                    'password' => [__('errors/database.root_password_policy')],
                ]);
            }

            throw new DatabaseOperationException($applied->reference);
        }

        if (! $this->check->works($engine, $password)) {
            $this->restore($engine, $previous, [$password, ...$current]);

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

    /**
     * Put root's previous login back, reaching it any way that still works.
     *
     * @param  list<string>  $passwords  the new password first, then the current one
     */
    private function restore(string $engine, string $previous, array $passwords): void
    {
        $sql = preg_replace('/^CREATE USER/', 'ALTER USER', $previous).'; FLUSH PRIVILEGES;';
        $result = $this->root->run($engine, $sql, 'root_password_restore', passwords: $passwords);

        if ($result->failed()) {
            Log::channel('server-ops')->critical('Root password change could not be undone', [
                'engine' => $engine,
                'reference' => $result->reference,
            ]);

            throw new DatabaseOperationException($result->reference);
        }
    }

    /**
     * @param  list<string>  $passwords  tried after the socket and the stored password
     */
    private function query(string $engine, string $sql, string $op, array $passwords = []): ServerOpsResult
    {
        return $this->root->run($engine, $sql, $op, ['--batch', '--skip-column-names', '--raw'], $passwords);
    }

    /**
     * Did the server answer "no" (SQLSTATE 28000, access denied)? The one failure a password can fix.
     */
    private function refused(ServerOpsResult $result): bool
    {
        $error = $result->errorOutput();

        return str_contains($error, '(28000)') || stripos($error, 'Access denied') !== false;
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
