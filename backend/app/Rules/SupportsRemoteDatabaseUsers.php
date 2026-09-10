<?php

namespace App\Rules;

use App\Services\Server\Databases\DatabaseManager;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses `remote` / `anywhere` on an engine whose accounts have no host.
 *
 * MySQL's identity is `'user'@'host'`, so creating a remote user *is* the
 * grant. A PostgreSQL role is cluster-wide and carries no host at all: which
 * addresses may reach it is decided by `pg_hba.conf`, a file this panel does
 * not own, parse or reload — and opening 5432 in the firewall achieves nothing
 * without it.
 *
 * So this refuses rather than lets the value through. Storing a preference no
 * code applies is the failure the OpenLiteSpeed PHP screen shipped with on
 * 2026-09-03: a 200, a saved setting, and no effect on the server. The user
 * finds out from the application, much later, and has no reason to suspect the
 * field they set.
 *
 * A rule rather than three copies of an `if`, because `connection_preference`
 * is accepted by three separate requests (create database with a user, create
 * a user, update a user) and the one that got missed would be the hole.
 */
class SupportsRemoteDatabaseUsers implements ValidationRule
{
    public function __construct(private ?string $engine) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // An unknown or absent engine is somebody else's error — `engine` has
        // its own `required`/`Rule::in`, and reporting this one too would bury
        // the real message under a second, stranger one.
        if ($this->engine === null || ! in_array($this->engine, app(DatabaseManager::class)->engineNames(), true)) {
            return;
        }

        if (! in_array($value, ['remote', 'anywhere'], true)) {
            return;
        }

        if (app(DatabaseManager::class)->supportsRemoteUsers($this->engine)) {
            return;
        }

        $fail(__('errors/database.remote_users_unsupported', [
            'engine' => (string) config("server.databases.engines.{$this->engine}.label", $this->engine),
        ]));
    }
}
