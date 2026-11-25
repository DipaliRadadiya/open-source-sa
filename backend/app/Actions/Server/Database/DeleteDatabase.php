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
        // A backup or restore running now would finish against a database that no longer exists
        // (a restore would recreate it behind the panel's back).
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

        // The engine owns teardown order and statements, so there is no `if` on the engine name.
        // A failed cleanup deliberately leaves the panel record intact, so the delete can be retried.
        $engine->teardownDatabase($database->name, $this->ownUsers($database, $engine));

        $this->activityLogger->log('database.deleted', null, ['name' => $database->name, 'engine' => $database->engine]);

        $users = $database->users->map(fn ($user) => [$user->connection_preference, $user->host])->all();

        // Its backups are archived, not deleted: they stay listed and downloadable and can be
        // restored into another database. Detached from the target first, because
        // `backups.backup_target_id` cascades and would delete the very rows this keeps.
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
     * One account can serve two databases on a server brought over from v7 or another panel
     * (Server Sync and Adopt record it under each), and dropping it breaks the other site. So a
     * user is dropped only when no other database has it in the panel and the engine reports no
     * rights on another one. Unsure means leave it.
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
