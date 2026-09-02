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

    /** Reclaim space / rebuild (SQL OPTIMIZE TABLE). No-op where unsupported. */
    public function optimize(string $database): void;

    /** Repair tables (SQL REPAIR TABLE). No-op where unsupported. */
    public function repair(string $database): void;

    /** Export the database to $path (mysqldump `--result-file` / mongodump `--archive`). Read-only. */
    public function dump(string $database, string $path): void;

    /**
     * Import $path into an existing database. **Destructive** — the caller is
     * expected to have dropped and recreated the schema first, so this is the
     * inverse of dump() rather than a merge.
     */
    public function restore(string $database, string $path): void;
}
