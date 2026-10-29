<?php

use App\Actions\Server\Database\DeleteDatabase;
use App\Enums\BackupStatus;
use App\Enums\RestoreStatus;
use App\Jobs\RunBackup;
use App\Jobs\RunRestore;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\Database;
use App\Models\DatabaseImport;
use App\Models\Restore;
use App\Models\StorageDestination;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Backups\BackupRunner;
use App\Services\Server\Backups\Storage\DestinationDisk;
use App\Services\Server\Backups\Storage\StorageDriverFactory;
use App\Services\Server\Restores\RestoreRunner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
 * Backups of a database on its own (2026-10-16; v7's type=database), and
 * archived backups. What matters: a database backup dumps that one database
 * and nothing of any site; a site's database stays with the site; deleting a
 * database archives its backups instead of losing them; a restore saves the
 * database first, replaces only the chosen database, and an archived backup
 * goes only into a database of the same engine.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    $this->fakeDisk = Storage::fake('destination');
    $this->app->bind(DestinationDisk::class, fn () => new DestinationDisk(
        app(StorageDriverFactory::class),
        fn (array $config) => $this->fakeDisk,
    ));

    $this->destination = StorageDestination::create([
        'name' => 'Backups', 'provider' => 's3',
        'config' => ['endpoint' => '', 'region' => 'us-east-1', 'bucket' => 'backups', 'access_key' => 'key', 'secret_key' => 'secret'],
    ]);

    $this->db = Database::create(['name' => 'ledger', 'engine' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']);
    DbBackupHost::reset();
});

/** The server: dumps and archives become real files, every command is recorded. */
final class DbBackupHost
{
    /** @var list<array{args: list<string>, input: string}> */
    public static array $runs = [];

    public static function reset(): void
    {
        self::$runs = [];

        Process::fake(function ($process) {
            $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
            self::$runs[] = ['args' => $args, 'input' => (string) $process->input];

            foreach ($args as $arg) {
                if (is_string($arg) && str_starts_with($arg, '--result-file=')) {
                    file_put_contents(substr($arg, 14), "CREATE TABLE t (id int);\n-- Dump completed on 2026-10-16\n");
                }
            }

            if (($args[0] ?? '') === 'tar' && in_array('-cf', $args, true)) {
                file_put_contents((string) tarArchivePath($args), str_repeat('x', 2048));
            }

            // A database restore unpacks only the dump, into the working directory.
            if (($args[0] ?? '') === 'tar' && in_array('-xzf', $args, true)) {
                $into = $args[array_search('-C', $args, true) + 1];
                File::ensureDirectoryExists($into);
                file_put_contents($into.'/db-ledger.sql', "CREATE TABLE t (id int);\n-- Dump completed on 2026-10-16\n");
            }

            return Process::result();
        });
    }

    public static function ran(string $needle): bool
    {
        return collect(self::$runs)->contains(fn ($r) => str_contains(implode(' ', $r['args']).' '.$r['input'], $needle));
    }
}

function dbBackupHeaders(?User $user = null): array
{
    return ['Authorization' => 'Bearer '.($user ?? test()->admin)->createToken('t')->plainTextToken];
}

function dbBackupTarget(?Database $database = null): BackupTarget
{
    return BackupTarget::create([
        'database_id' => ($database ?? test()->db)->id,
        'storage_destination_id' => test()->destination->id,
        'type' => 'database', 'retention_count' => 3, 'enabled' => true, 'frequency' => 'daily',
    ]);
}

function dbBackupRow(?BackupTarget $target = null, string $engine = 'mysql'): Backup
{
    $target ??= dbBackupTarget();
    $key = 'databases/ledger-'.$target->database_id.'/x-'.Str::uuid().'.tar.gz';
    test()->fakeDisk->put($key, str_repeat('x', 2048));

    return Backup::create([
        'backup_target_id' => $target->id, 'database_id' => $target->database_id,
        'subject_name' => $target->database->name, 'subject_engine' => $engine,
        'type' => 'database', 'status' => BackupStatus::Verified, 'size_bytes' => 2048,
        'manifest' => ['key' => $key], 'verified_at' => now(),
    ]);
}

describe('the schedule', function () {
    it('saves one for a database on its own, always of type database', function () {
        $this->withHeaders(dbBackupHeaders())->putJson("/api/databases/{$this->db->id}/backup-target", [
            'storage_destination_id' => $this->destination->id, 'retention_count' => 5, 'frequency' => 'daily', 'enabled' => true,
        ])->assertOk()
            ->assertJsonPath('backup_target.database_id', $this->db->id)
            ->assertJsonPath('backup_target.application_id', null)
            ->assertJsonPath('backup_target.type', 'database');

        expect(BackupTarget::where('database_id', $this->db->id)->count())->toBe(1);
    });

    it('refuses a site\'s database — it is backed up with the site — and says which site', function () {
        $site = Application::forceCreate([
            'system_user_id' => SystemUser::create(['username' => 'owner', 'home_path' => '/home/owner'])->id,
            'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.test', 'site_type' => 'php', 'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/',
        ]);
        $this->db->update(['application_id' => $site->id]);

        $this->withHeaders(dbBackupHeaders())->putJson("/api/databases/{$this->db->id}/backup-target", [
            'storage_destination_id' => $this->destination->id, 'retention_count' => 5, 'frequency' => 'daily', 'enabled' => true,
        ])->assertStatus(422)->assertJsonFragment(['database' => [__('backup.errors.database_backed_up_with_site', ['site' => 'Shop'])]]);

        $this->withHeaders(dbBackupHeaders())->getJson("/api/databases/{$this->db->id}/backup-target")
            ->assertJsonPath('backed_up_with.application_name', 'Shop');
    });

    it('lets a viewer see it but not change it', function () {
        app('auth')->forgetGuards();
        $viewer = User::factory()->create();
        grantPermission($viewer, 'database', view: true, manage: false);

        $this->withHeaders(dbBackupHeaders($viewer))->getJson("/api/databases/{$this->db->id}/backup-target")->assertOk();
        $this->withHeaders(dbBackupHeaders($viewer))->putJson("/api/databases/{$this->db->id}/backup-target", [
            'storage_destination_id' => $this->destination->id, 'retention_count' => 5, 'frequency' => 'daily', 'enabled' => true,
        ])->assertForbidden();
    });

    it('is picked up by the scheduler like a site\'s', function () {
        Queue::fake();
        $target = dbBackupTarget();

        $this->artisan('backups:run-due')->assertSuccessful();

        Queue::assertPushed(RunBackup::class, fn (RunBackup $job) => $job->backupTargetId === $target->id);
    });
});

describe('a run', function () {
    it('dumps that database alone, packs only the dump, and files it under databases/', function () {
        $backup = app(BackupRunner::class)->run(dbBackupTarget());

        $tar = collect(DbBackupHost::$runs)->first(fn ($r) => ($r['args'][0] ?? '') === 'tar');

        expect($backup->status)->toBe(BackupStatus::Verified)
            ->and($backup->database_id)->toBe($this->db->id)
            ->and($backup->application_id)->toBeNull()
            ->and($backup->subject_name)->toBe('ledger')
            ->and($backup->subject_engine)->toBe('mysql')
            ->and($backup->manifest['key'])->toStartWith("databases/ledger-{$this->db->id}/")
            ->and($backup->manifest['databases'][0]['name'])->toBe('ledger')
            // The dump and nothing else: no site directory in the archive.
            ->and(collect($tar['args'])->filter(fn ($a) => str_ends_with((string) $a, '.sql'))->values()->all())->toBe(['db-ledger.sql'])
            ->and(in_array('public_html', $tar['args'], true))->toBeFalse();

        $this->fakeDisk->assertExists($backup->manifest['key']);
    });

    it('fails, not "verifies an empty archive", when its database is gone', function () {
        // Deleted between queueing and running: the relation is gone.
        $target = dbBackupTarget();
        $target->setRelation('database', null);

        expect(app(BackupRunner::class)->run($target)->status)->toBe(BackupStatus::Failed);
    });

    it('lists only its own backups on the database\'s page, and filters the shared list', function () {
        $mine = dbBackupRow();
        $other = Database::create(['name' => 'other', 'engine' => 'mysql']);
        dbBackupRow(dbBackupTarget($other));

        $this->withHeaders(dbBackupHeaders())->getJson("/api/databases/{$this->db->id}/backups")
            ->assertOk()->assertJsonCount(1, 'backups')->assertJsonPath('backups.0.id', $mine->id)
            ->assertJsonPath('backups.0.database_name', 'ledger')->assertJsonPath('backups.0.archived', false);

        $this->withHeaders(dbBackupHeaders())->getJson("/api/backups?filter[database_id]={$this->db->id}")
            ->assertJsonCount(1, 'backups');
    });
});

describe('deleting the database', function () {
    it('archives its backups — kept, named, listed — and removes only the schedule', function () {
        $backup = dbBackupRow();
        $target = $backup->target;

        app(DeleteDatabase::class)->execute($this->db);

        $backup->refresh();

        expect(BackupTarget::find($target->id))->toBeNull()
            ->and($backup->exists)->toBeTrue()
            ->and($backup->database_id)->toBeNull()
            ->and($backup->backup_target_id)->toBeNull()
            ->and($backup->isArchived())->toBeTrue()
            ->and($backup->subjectName())->toBe('ledger');

        $this->withHeaders(dbBackupHeaders())->getJson('/api/backups?filter[archived]=1&filter[engine]=mysql')
            ->assertJsonCount(1, 'backups')->assertJsonPath('backups.0.archived', true)->assertJsonPath('backups.0.database_name', 'ledger');
        $this->fakeDisk->assertExists($backup->manifest['key']);
    });

    it('is refused while a backup of it is running', function () {
        Backup::create(['backup_target_id' => dbBackupTarget()->id, 'database_id' => $this->db->id, 'subject_engine' => 'mysql', 'type' => 'database', 'status' => BackupStatus::Running]);

        expect(fn () => app(DeleteDatabase::class)->execute($this->db))
            ->toThrow(ValidationException::class);

        expect(Database::find($this->db->id))->not->toBeNull();
    });
});

describe('restoring', function () {
    it('queues a restore of its own backup into it, named by typing the database', function () {
        Queue::fake();
        $backup = dbBackupRow();

        $this->withHeaders(dbBackupHeaders())->postJson("/api/databases/{$this->db->id}/backups/{$backup->id}/restore", ['confirm' => 'nope'])
            ->assertStatus(422)->assertJsonValidationErrors('confirm');

        $this->withHeaders(dbBackupHeaders())->postJson("/api/databases/{$this->db->id}/backups/{$backup->id}/restore", ['confirm' => 'ledger'])
            ->assertStatus(202);

        $restore = Restore::latest('id')->first();
        expect($restore->database_id)->toBe($this->db->id)->and($restore->application_id)->toBeNull();
        Queue::assertPushed(RunRestore::class, fn (RunRestore $job) => $job->databaseId === $this->db->id);
    });

    it('saves the database first, then replaces only that database — no site file, no process', function () {
        $backup = dbBackupRow();
        $restore = Restore::create(['backup_id' => $backup->id, 'database_id' => $this->db->id, 'type' => 'database', 'status' => RestoreStatus::Pending, 'reference' => (string) Str::uuid()]);

        $done = app(RestoreRunner::class)->run($restore->fresh(['backup', 'database.backupTarget']));

        $safety = Backup::find($done->safety_backup_id);
        $order = collect(DbBackupHost::$runs)->map(fn ($r) => implode(' ', $r['args']).' '.$r['input']);
        $dumpAt = $order->search(fn ($c) => str_contains($c, '--result-file='));
        $dropAt = $order->search(fn ($c) => str_contains($c, 'DROP DATABASE IF EXISTS `ledger`'));

        expect($done->status)->toBe(RestoreStatus::Succeeded)
            ->and($safety->is_safety)->toBeTrue()
            ->and($safety->database_id)->toBe($this->db->id)
            ->and($dropAt)->toBeGreaterThan($dumpAt)
            ->and(DbBackupHost::ran('CREATE DATABASE `ledger`'))->toBeTrue()
            ->and(DbBackupHost::ran('source '))->toBeTrue()
            ->and(DbBackupHost::ran('systemctl'))->toBeFalse()
            ->and(DbBackupHost::ran('.rollback-'))->toBeFalse();
    });

    it('puts an archived backup into another database of the same engine, and only that', function () {
        $backup = dbBackupRow();
        app(DeleteDatabase::class)->execute($this->db);
        $fresh = Database::create(['name' => 'ledger2', 'engine' => 'mysql']);
        dbBackupTarget($fresh);
        DbBackupHost::$runs = [];
        $restore = Restore::create(['backup_id' => $backup->id, 'database_id' => $fresh->id, 'type' => 'database', 'status' => RestoreStatus::Pending, 'reference' => (string) Str::uuid()]);

        $done = app(RestoreRunner::class)->run($restore->fresh(['backup', 'database.backupTarget']));

        expect($done->status)->toBe(RestoreStatus::Succeeded)
            ->and(DbBackupHost::ran('DROP DATABASE IF EXISTS `ledger2`'))->toBeTrue()
            ->and(DbBackupHost::ran('DROP DATABASE IF EXISTS `ledger`;'))->toBeFalse();
    });

    it('refuses what it cannot do safely', function (string $case) {
        Queue::fake();
        $backup = dbBackupRow();
        $into = $this->db;
        $confirm = 'ledger';

        match ($case) {
            // Another database's own backup is not archived: never cross-restored.
            'other database' => ($into = tap(Database::create(['name' => 'b', 'engine' => 'mysql']), fn ($d) => dbBackupTarget($d))) && ($confirm = 'b'),
            'engine mismatch' => (function () use (&$into, &$confirm) {
                app(DeleteDatabase::class)->execute($this->db);
                $into = Database::create(['name' => 'pg', 'engine' => 'postgresql']);
                dbBackupTarget($into);
                $confirm = 'pg';
            })(),
            // No schedule means no safety backup, so no restore.
            'no schedule' => (function () use (&$into, &$confirm) {
                app(DeleteDatabase::class)->execute($this->db);
                $into = Database::create(['name' => 'bare', 'engine' => 'mysql']);
                $confirm = 'bare';
            })(),
            'unverified' => $backup->update(['status' => BackupStatus::Failed]),
            'import running' => DatabaseImport::create(['database_id' => $this->db->id, 'database_name' => 'ledger', 'engine' => 'mysql', 'original_name' => 'x.sql', 'size_bytes' => 1, 'status' => 'queued', 'user_id' => $this->admin->id]),
        };

        $this->withHeaders(dbBackupHeaders())->postJson("/api/databases/{$into->id}/backups/{$backup->id}/restore", ['confirm' => $confirm])
            ->assertStatus(422);
        Queue::assertNotPushed(RunRestore::class);
    })->with(['other database', 'engine mismatch', 'no schedule', 'unverified', 'import running']);

    it('is not done through the shared backups screen', function () {
        $backup = dbBackupRow();

        $this->withHeaders(dbBackupHeaders())->postJson("/api/backups/{$backup->id}/restore", ['confirm' => 'ledger'])
            ->assertStatus(422)->assertJsonFragment(['backup' => [__('backup.errors.restore_database_backup_here')]]);
    });

    it('blocks an export of the same database while it runs', function () {
        $backup = dbBackupRow();
        Restore::create(['backup_id' => $backup->id, 'database_id' => $this->db->id, 'type' => 'database', 'status' => RestoreStatus::Running, 'reference' => (string) Str::uuid()]);

        $this->withHeaders(dbBackupHeaders())->postJson("/api/databases/{$this->db->id}/export")->assertStatus(422);
    });
});

it('serves a database\'s backup only under that database', function () {
    $other = Database::create(['name' => 'other', 'engine' => 'mysql']);
    $backup = dbBackupRow(dbBackupTarget($other));

    $this->withHeaders(dbBackupHeaders())->deleteJson("/api/databases/{$this->db->id}/backups/{$backup->id}")->assertNotFound();
    $this->withHeaders(dbBackupHeaders())->getJson("/api/databases/{$this->db->id}/backups/{$backup->id}/download")->assertNotFound();
});

/*
 * RC-19 (frontend QA, 16 Oct): a database's own restore carried its database
 * only in the row, never in the response, and GET /restores could not be
 * asked for one database. Its Backups tab lost the restore on reload, and a
 * database restore looked like a deleted site's (both have no application).
 */
describe('finding a database\'s restores', function () {
    function dbRestore(Backup $backup, ?int $databaseId): Restore
    {
        return Restore::create([
            'backup_id' => $backup->id, 'database_id' => $databaseId, 'type' => 'database',
            'status' => RestoreStatus::Succeeded, 'reference' => (string) Str::uuid(),
        ]);
    }

    it('names the database it restored, and filters by it', function () {
        $restore = dbRestore(dbBackupRow(), $this->db->id);
        $other = Database::create(['name' => 'other', 'engine' => 'mariadb', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']);
        dbRestore(dbBackupRow(dbBackupTarget($other), 'mariadb'), $other->id);

        $this->withHeaders(dbBackupHeaders())->getJson("/api/restores?filter[database_id]={$this->db->id}")
            ->assertOk()
            ->assertJsonCount(1, 'restores')
            ->assertJsonPath('restores.0.id', $restore->id)
            ->assertJsonPath('restores.0.database_id', $this->db->id)
            ->assertJsonPath('restores.0.database_name', 'ledger')
            ->assertJsonPath('restores.0.database_engine', 'mysql')
            ->assertJsonPath('restores.0.application_id', null);
    });

    it('keeps the name after the database is deleted, from the backup', function () {
        $restore = dbRestore(dbBackupRow(), $this->db->id);
        app(DeleteDatabase::class)->execute($this->db);

        $this->withHeaders(dbBackupHeaders())->getJson("/api/restores/{$restore->id}")
            ->assertOk()
            ->assertJsonPath('restore.database_id', null)
            ->assertJsonPath('restore.database_name', 'ledger')
            ->assertJsonPath('restore.database_engine', 'mysql');
    });

    it('sends no database for a site\'s restore', function () {
        $application = Application::forceCreate([
            'system_user_id' => SystemUser::create(['username' => 'rc19', 'home_path' => '/home/rc19'])->id,
            'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.test', 'site_type' => 'php',
            'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/',
        ]);
        $backup = Backup::create(['application_id' => $application->id, 'type' => 'full', 'status' => BackupStatus::Verified, 'manifest' => ['databases' => []]]);
        $restore = Restore::create(['backup_id' => $backup->id, 'application_id' => $application->id, 'type' => 'filesystem', 'status' => RestoreStatus::Succeeded, 'reference' => (string) Str::uuid()]);

        $this->withHeaders(dbBackupHeaders())->getJson("/api/restores/{$restore->id}")
            ->assertOk()
            ->assertJsonPath('restore.database_id', null)
            ->assertJsonPath('restore.database_name', null)
            ->assertJsonPath('restore.database_engine', null);
    });

    it('refuses a database that does not exist as a filter', function () {
        $this->withHeaders(dbBackupHeaders())->getJson('/api/restores?filter[database_id]=999999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('filter.database_id');
    });
});

/*
 * RC-23 (frontend QA, 17 Oct): database-only schedules had no list — GET
 * /backup-targets returned applications only, so the overview needed one
 * request per database to show which were backed up.
 */
describe('the overview\'s database rows', function () {
    it('lists databases that are not part of a site, with their schedule and last backup', function () {
        $backup = dbBackupRow();
        $plain = Database::create(['name' => 'archive', 'engine' => 'pgsql']);
        $site = Application::forceCreate([
            'system_user_id' => SystemUser::create(['username' => 'rc23', 'home_path' => '/home/rc23'])->id,
            'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.test', 'site_type' => 'php',
            'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/',
        ]);
        Database::create(['name' => 'shop_db', 'engine' => 'mysql', 'application_id' => $site->id]);

        $rows = $this->withHeaders(dbBackupHeaders())->getJson('/api/backup-targets?kind=database&sort=name')
            ->assertOk()
            ->assertJsonPath('meta.kind', 'database')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.protected', 1)
            ->assertJsonPath('meta.unprotected', 1)
            ->assertJsonPath('meta.configured', 1)
            ->json('backup_targets');

        expect(array_column($rows, 'database_name'))->toBe(['archive', 'ledger'])
            ->and($rows[0]['backup_target'])->toBeNull()
            ->and($rows[0]['database_engine'])->toBe('pgsql')
            ->and($rows[1]['database_id'])->toBe($this->db->id)
            ->and($rows[1]['backup_target']['frequency'])->toBe('daily')
            ->and($rows[1]['last_backup']['id'])->toBe($backup->id);
    });

    it('filters to the unprotected ones and searches by name', function () {
        dbBackupRow();
        Database::create(['name' => 'archive', 'engine' => 'mysql']);

        $this->withHeaders(dbBackupHeaders())->getJson('/api/backup-targets?kind=database&filter[protected]=0')
            ->assertOk()->assertJsonCount(1, 'backup_targets')->assertJsonPath('backup_targets.0.database_name', 'archive');

        $this->withHeaders(dbBackupHeaders())->getJson('/api/backup-targets?kind=database&search=ledg')
            ->assertOk()->assertJsonCount(1, 'backup_targets')->assertJsonPath('backup_targets.0.database_name', 'ledger');
    });

    it('still lists applications without a kind, and refuses an unknown one', function () {
        $this->withHeaders(dbBackupHeaders())->getJson('/api/backup-targets')
            ->assertOk()->assertJsonMissingPath('meta.kind');

        $this->withHeaders(dbBackupHeaders())->getJson('/api/backup-targets?kind=server')
            ->assertUnprocessable()->assertJsonValidationErrors('kind');

        // A site's sort that databases do not have.
        $this->withHeaders(dbBackupHeaders())->getJson('/api/backup-targets?kind=database&sort=domain')
            ->assertUnprocessable()->assertJsonValidationErrors('sort');
    });
});

it('lets the user restore a database without the copy first (2026-10-29)', function () {
    Queue::fake();
    $backup = dbBackupRow();
    $url = "/api/databases/{$this->db->id}/backups/{$backup->id}/restore";

    $this->withHeaders(dbBackupHeaders())->postJson($url, ['confirm' => 'ledger'])
        ->assertStatus(202)->assertJsonPath('restore.safety_copy', true);
    expect(Restore::latest('id')->first()->safety_copy)->toBeTrue();

    Restore::query()->delete();
    $this->withHeaders(dbBackupHeaders())->postJson($url, ['confirm' => 'ledger', 'safety_copy' => false])
        ->assertStatus(202)->assertJsonPath('restore.safety_copy', false);
    expect(Restore::latest('id')->first()->safety_copy)->toBeFalse();
});

it('refuses a safety_copy that is not true or false', function () {
    $backup = dbBackupRow();

    $this->withHeaders(dbBackupHeaders())->postJson("/api/databases/{$this->db->id}/backups/{$backup->id}/restore", ['confirm' => 'ledger', 'safety_copy' => 'maybe'])
        ->assertJsonValidationErrors('safety_copy');
});
