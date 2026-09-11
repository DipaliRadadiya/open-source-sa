<?php

namespace App\Contracts;

/**
 * A database engine strategy. `SqlEngine` covers MySQL + MariaDB (shared
 * client + DDL); `MongoEngine` covers MongoDB. Each is built for one
 * configured admin connection and runs its operations locally via the client
 * binary (connection creds in a 0600 auth file, statements over stdin — never
 * a password on argv). Every mutating method throws a translated
 * DatabaseOperationException on failure. Identifiers are validated upstream by
 * the FormRequests (DDL can't be parameterised).
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
     * Read-only, for adopting a migrated server. The password is deliberately
     * absent: the engine holds a hash, and a hash cannot be turned back into
     * one — see the nullable `database_users.password` column.
     *
     * @return array<int, array{username: string, host: string, databases: array<int, string>}>
     */
    public function listUsers(): array;

    /**
     * Whether a name is free for both a new database and its dedicated user.
     *
     * This is a strict preflight: unlike discovery lists, an unavailable
     * server must throw rather than look empty and falsely approve a name.
     */
    public function identifierAvailable(string $name, string $host = 'localhost'): bool;

    public function createDatabase(string $name, ?string $charset, ?string $collation): void;

    public function dropDatabase(string $name): void;

    /**
     * Remove a database and the users that exist only to reach it.
     *
     * Teardown order and teardown *statements* are the engine's to decide, not
     * the caller's, because the two are not separable. MySQL removes the
     * database first so a failed drop cannot leave a live database with no way
     * in; PostgreSQL keeps that same order and must then drop its roles
     * *differently* from `dropUser()`, because that method's cleanup runs
     * inside the database it was handed and the database is by then gone.
     *
     * A user cleanup that fails deliberately leaves the panel record intact,
     * so the same delete can be retried. Every engine's database drop is
     * `IF EXISTS` for that reason — a retry has to finish the cleanup rather
     * than fail on the database it already removed.
     *
     * @param  array<int, array{username: string, host: string}>  $users
     */
    public function teardownDatabase(string $name, array $users): void;

    /** Size in bytes (0 when unknown). */
    public function databaseSize(string $name): int;

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
     * There was an `optimize()` and a `repair()` here, removed 2026-09-08.
     *
     * Not because nothing implemented them — both engines did — but because
     * what they implemented was not what they claimed. `REPAIR TABLE` is a
     * MyISAM/ARCHIVE/CSV operation; on InnoDB, which is every table this panel
     * has managed, MariaDB answers with a *note* and exits 0, so "Repaired
     * database" was logged for an operation that could not run. And `OPTIMIZE
     * TABLE` on InnoDB is a whole-table rebuild, which this interface's callers
     * ran inside an HTTP request.
     *
     * The lesson worth keeping is about the interface, not the SQL: "no-op
     * where unsupported" reads as harmless and is not. A method that silently
     * does nothing on the only storage engine anybody uses is indistinguishable
     * from one that works, and every layer above it will report success.
     */

    /** Export the database to $path (mysqldump `--result-file` / mongodump `--archive`). Read-only. */
    public function dump(string $database, string $path): void;

    /**
     * Import $path into an existing database. **Destructive** — the caller is
     * expected to have dropped and recreated the schema first, so this is the
     * inverse of dump() rather than a merge.
     */
    public function restore(string $database, string $path): void;
}
