<?php

namespace App\Console\Commands;

use App\Models\Database;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Databases\PgsqlEngine;
use Illuminate\Console\Command;
use Throwable;

/**
 * Closes bug #35 on databases made before the fix.
 *
 * PostgreSQL grants CONNECT on every new database to PUBLIC, so one site's
 * user could open another site's database and list its tables. New databases
 * revoke that at creation; this does the same for the ones that already
 * exist, keeping access for every role that really uses each one (see
 * {@see PgsqlEngine::restrictToItsUsers()}).
 *
 * Only databases the panel lists. Run by the updater and the deploy runbook,
 * like `fail2ban:resync`: a fix that only reaches new databases leaves every
 * existing server exactly as exposed as before. Idempotent, and never fatal: a
 * database it cannot change keeps the access it had, which is today's state.
 */
class RestrictPostgresDatabases extends Command
{
    protected $signature = 'databases:restrict-postgres';

    protected $description = "Let only each PostgreSQL database's own users connect to it";

    public function handle(DatabaseManager $manager): int
    {
        $databases = Database::query()->where('engine', 'postgresql')->with('users')->get();

        if ($databases->isEmpty() || ! $manager->serverInstalled('postgresql')) {
            $this->components->info('No PostgreSQL databases to check.');

            return self::SUCCESS;
        }

        $engine = $manager->engine('postgresql');

        if (! $engine instanceof PgsqlEngine || ! $engine->available()) {
            $this->components->warn('PostgreSQL is not reachable; databases left as they are.');

            return self::SUCCESS;
        }

        $restricted = $current = 0;
        $failed = [];

        foreach ($databases as $database) {
            try {
                $engine->restrictToItsUsers($database->name, $database->users->pluck('username')->all())
                    ? $restricted++
                    : $current++;
            } catch (Throwable) {
                $failed[] = $database->name;
            }
        }

        $this->line(sprintf(
            'PostgreSQL databases: %d restricted to their own users, %d already restricted, %d failed.',
            $restricted, $current, count($failed),
        ));

        if ($failed !== []) {
            $this->components->warn('Left as they were: '.implode(', ', $failed));
        }

        return self::SUCCESS;
    }
}
