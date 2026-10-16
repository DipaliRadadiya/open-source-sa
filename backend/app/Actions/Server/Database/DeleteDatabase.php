<?php

namespace App\Actions\Server\Database;

use App\Contracts\DatabaseEngine;
use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\Restore;
use App\Services\ActivityLogger;
use App\Services\Server\Databases\DatabaseFirewall;
use App\Services\Server\Databases\DatabaseManager;
use Illuminate\Validation\ValidationException;

class DeleteDatabase
{
    public function __construct(
        private DatabaseManager $manager,
        private ActivityLogger $activityLogger,
        private DatabaseFirewall $firewall,
    ) {}

    public function execute(Database $database): void
    {
        // A backup or restore of this database running right now would finish
        // against a database that no longer exists — and a restore would
        // recreate it behind the panel's back.
        $busy = Backup::query()->where('database_id', $database->id)
            ->whereIn('status', [BackupStatus::Pending->value, BackupStatus::Running->value, BackupStatus::Verifying->value])
            ->exists()
            || Restore::inProgressForDatabase($database->id);

        if ($busy) {
            throw ValidationException::withMessages([
                'database' => [__('backup.errors.delete_database_backup_running')],
            ]);
        }

        $engine = $this->manager->engine($database->engine);

        // Teardown order — and which statements it takes — belongs to the
        // engine. This action had both inlined, in the order MySQL needs;
        // PostgreSQL needs the same order and different statements, and
        // discovering that here would mean an `if` on the engine name.
        //
        // A failed cleanup deliberately leaves the panel record intact, so the
        // same delete can be retried until it finishes.
        $engine->teardownDatabase($database->name, $this->ownUsers($database, $engine));

        $this->activityLogger->log('database.deleted', null, ['name' => $database->name, 'engine' => $database->engine]);

        $users = $database->users->map(fn ($user) => [$user->connection_preference, $user->host])->all();

        // Its backups are archived, not deleted (decision 2026-10-16): they
        // keep their name and engine, stay listed and downloadable, and can be
        // restored into another database. Detached from the target first —
        // the target goes with the database, and `backups.backup_target_id`
        // cascades, so leaving it set would delete the very rows this keeps.
        Backup::query()->where('database_id', $database->id)->update([
            'backup_target_id' => null,
            'database_id' => null,
        ]);

        $database->delete(); // cascade removes database_users rows and its backup target

        foreach ($users as [$preference, $host]) {
            $this->firewall->release($database->engine, $preference, $host);
        }
    }

    /**
     * The users to drop with the database: only those that serve nothing else.
     *
     * One account often serves two databases on a server brought over from
     * v7 or another panel, and Server Sync and Adopt record it under each.
     * Dropping it with the first database broke the site using the second (on
     * PostgreSQL the drop failed outright). So a user is dropped only when no
     * other database has it in the panel and the engine reports no rights on
     * another one. An adopted user the engine cannot account for is kept:
     * unsure means leave it (bug #34).
     *
     * @return array<int, array{username: string, host: string}>
     */
    private function ownUsers(Database $database, DatabaseEngine $engine): array
    {
        if ($database->users->isEmpty()) {
            return [];
        }

        $reported = collect($engine->listUsers())->keyBy(fn (array $user) => $user['username'].'@'.$user['host']);

        return $database->users
            ->reject(function (DatabaseUser $user) use ($database, $reported) {
                $elsewhere = DatabaseUser::query()
                    ->where('username', $user->username)
                    ->where('host', $user->host)
                    ->where('database_id', '!=', $database->id)
                    ->whereHas('database', fn ($query) => $query->where('engine', $database->engine))
                    ->exists();

                $engineView = $reported->get($user->username.'@'.$user->host);
                $otherGrants = $engineView !== null && array_diff($engineView['databases'], [$database->name]) !== [];
                $unknownAdopted = $engineView === null && $user->password === null;

                return $elsewhere || $otherGrants || $unknownAdopted;
            })
            ->map(fn (DatabaseUser $user) => ['username' => $user->username, 'host' => $user->host])
            ->values()
            ->all();
    }
}
