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
}
