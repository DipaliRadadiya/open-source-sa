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

    /** Cumulative query/op counter for the QPS collector. */
    public function queryCount(): int;

    /** Reclaim space / rebuild (SQL OPTIMIZE TABLE). No-op where unsupported. */
    public function optimize(string $database): void;

    /** Repair tables (SQL REPAIR TABLE). No-op where unsupported. */
    public function repair(string $database): void;
}
