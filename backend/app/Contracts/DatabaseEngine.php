<?php

namespace App\Contracts;

use App\Services\Server\Databases\Imports\ImportSanitizer;

/**
 * A database engine strategy, built for one admin connection. Runs via the client binary with
 * creds in a 0600 auth file and statements over stdin (never a password on argv). Mutating
 * methods throw a translated DatabaseOperationException. Identifiers are validated upstream
 * by the FormRequests (DDL can't be parameterised).
 */
interface DatabaseEngine
{
    public function engine(): string; // mysql | mariadb | mongodb

    public function driver(): string; // sql | mongo

    /** Client installed AND reachable with the configured connection. */
    public function available(): bool;

    /** Engine version string, or null when unreachable. */
    public function version(): ?string;

    /**
     * User database names (system schemas excluded).
     *
     * @return array<int, string>
     */
    public function listDatabases(): array;

    /**
     * Accounts that exist in the engine, with the databases each can reach.
     *
     * Read-only, for adopting a migrated server. The password is absent on purpose: the engine
     * holds only a hash (see the nullable `database_users.password` column).
     *
     * @return array<int, array{username: string, host: string, databases: array<int, string>}>
     */
    public function listUsers(): array;

    /**
     * Whether a name is free for both a new database and its dedicated user.
     *
     * Strict preflight: an unavailable server must throw, not look empty and approve a name.
     */
    public function identifierAvailable(string $name, string $host = 'localhost'): bool;

    /**
     * Whether the account a new user would become already exists: the same name and host on
     * MySQL/MariaDB, the role on PostgreSQL, the user in that database on MongoDB. Throws when
     * the server cannot answer, like identifierAvailable().
     */
    public function userExists(string $username, string $host, string $database): bool;

    public function createDatabase(string $name, ?string $charset, ?string $collation): void;

    public function dropDatabase(string $name): void;

    /**
     * Remove a database and the users that exist only to reach it.
     *
     * Teardown order and statements are the engine's to decide. The database goes first so a
     * failed drop cannot leave a live database with no way in; PostgreSQL then drops its roles
     * differently from `dropUser()`, whose cleanup runs inside the now-gone database.
     *
     * A failed user cleanup deliberately leaves the panel record intact so the delete can be
     * retried, which is why every database drop is `IF EXISTS`.
     *
     * @param  array<int, array{username: string, host: string}>  $users
     */
    public function teardownDatabase(string $name, array $users): void;

    /**
     * Size in bytes; null when it could not be read. Never 0 for "unknown": a stored 0 would
     * overwrite the real size and show the database as empty.
     */
    public function databaseSize(string $name): ?int;

    /**
     * The character set and collation a database was created with.
     *
     * For adoption, so adopted databases show their charset like panel-created ones.
     *
     * Nulls where the concept does not apply or cannot be read, rather than a guessed default.
     *
     * @return array{charset: ?string, collation: ?string}
     */
    public function describeDatabase(string $name): array;

    /** Create the user + grant it full access to its one database. */
    public function createUser(string $username, string $host, string $password, string $database): void;

    public function dropUser(string $username, string $host, string $database): void;

    public function setPassword(string $username, string $host, string $password, string $database): void;

    /**
     * Rename/rehost a user. SQL uses RENAME USER (preserves grants, ignores
     * $password); Mongo (no rename) drops + recreates with $password.
     */
    public function renameUser(string $username, string $host, string $newUsername, string $newHost, string $password, string $database): void;

    // ---- P2: monitoring + maintenance ----

    /**
     * Live server processes / operations.
     *
     * @return array<int, array<string, mixed>>
     */
    public function processes(): array;

    public function killProcess(string $id): void;

    /**
     * Health snapshot (connections, uptime, query/op counters, …).
     *
     * @return array<string, int|string|null>
     */
    public function status(): array;

    /**
     * Tables/collections in a database with row count + size.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tables(string $database): array;

    /*
     * No `optimize()`/`repair()` on purpose: `REPAIR TABLE` does nothing on InnoDB (MariaDB
     * answers with a note and exits 0), and `OPTIMIZE TABLE` is a whole-table rebuild. A method
     * that silently no-ops looks like one that works, so every layer above reports success.
     */

    /** Export the database to $path (mysqldump `--result-file` / mongodump `--archive`). Read-only. */
    public function dump(string $database, string $path): void;

    /**
     * Import $path into an existing database. **Destructive**: the caller drops and recreates
     * the schema first, so this is the inverse of dump(), not a merge.
     */
    public function restore(string $database, string $path): void;

    /**
     * Load a user-supplied file into a freshly recreated database, connected **as that
     * database's own user**, never the panel's admin account. That is the containment: a dump
     * saying `USE other_db` reaches only what this user may reach.
     *
     * `$path` is already decompressed and cleaned for SQL engines ({@see ImportSanitizer});
     * for MongoDB it is the uploaded archive itself, gzip or not as `$gzip` says.
     * Throws DatabaseOperationException on failure.
     */
    public function importAs(string $database, string $path, string $username, string $password, bool $gzip = false): void;

    /**
     * Give a dropped-and-recreated database back its recorded users, before
     * {@see restore()}, which may load its contents as them.
     *
     * Only PostgreSQL has work to do: a MySQL grant outlives its database and a MongoDB user
     * lives outside it, but a PostgreSQL owner and CONNECT grants go with the database.
     *
     * @param  array<int, string>  $usernames
     */
    public function reattachUsers(string $database, array $usernames): void;
}
