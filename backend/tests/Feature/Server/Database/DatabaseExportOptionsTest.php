<?php

use App\Actions\Server\Database\ExportDatabase;
use App\Enums\ExportStatus;
use App\Models\ActivityLog;
use App\Models\Database;
use App\Models\DatabaseExport;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/*
 * Export options (Dipali, 2026-10-26): an export can delete itself after
 * 1–30 days (default: kept until the user deletes it), and a user's SQL export
 * is gzipped.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    $this->dir = sys_get_temp_dir().'/sv-export-options-'.getmypid();
    File::deleteDirectory($this->dir);
    File::ensureDirectoryExists($this->dir);
    config([
        'server.databases.export_dir' => $this->dir.'/exports',
        'server.databases.auth_file_dir' => $this->dir,
    ]);

    // The dump client writes what --result-file names, like the real one.
    $this->dumped = str_repeat("INSERT INTO t VALUES (1, 'same row again');\n", 2000);
    Process::fake(function ($process) {
        foreach ((array) $process->command as $arg) {
            if (str_starts_with($arg, '--result-file=') || str_starts_with($arg, '--file=') || str_starts_with($arg, '--archive=')) {
                file_put_contents(substr($arg, strpos($arg, '=') + 1), $this->dumped);
            }
        }

        return Process::result(output: '1');
    });

    $this->user = User::factory()->admin()->create();
    $this->actingAs($this->user);
    $this->db = Database::create(['name' => 'shop', 'engine' => 'mysql']);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
    Carbon::setTestNow();
});

function optionsExport(array $attributes = []): DatabaseExport
{
    return DatabaseExport::create([
        'database_id' => test()->db->id,
        'database_name' => test()->db->name,
        'engine' => test()->db->engine,
        'status' => ExportStatus::Running,
        'user_id' => test()->user->id,
        ...$attributes,
    ]);
}

describe('how long an export is kept', function () {

    beforeEach(fn () => Queue::fake());

    it('keeps an export until the user deletes it unless asked otherwise', function () {
        $this->postJson("/api/databases/{$this->db->id}/export")
            ->assertStatus(202)
            ->assertJsonPath('export.expires_at', null);
    });

    it('records when an export should delete itself', function () {
        Carbon::setTestNow(Carbon::parse('2026-10-26 10:00:00'));

        $this->postJson("/api/databases/{$this->db->id}/export", ['keep_days' => 7])
            ->assertStatus(202)
            ->assertJsonPath('export.expires_at', '02-11-2026 10:00:00');
    });

    it('accepts only the listed periods', function (mixed $keepDays) {
        $this->postJson("/api/databases/{$this->db->id}/export", ['keep_days' => $keepDays])
            ->assertStatus(422)
            ->assertJsonValidationErrors('keep_days');

        expect(DatabaseExport::count())->toBe(0);
    })->with(['zero' => [0], 'not listed' => [5], 'too long' => [365], 'not a number' => ['week']]);

    it('refuses a read-only user even with a period', function () {
        $viewer = User::factory()->create();
        grantPermission($viewer, 'database');
        $this->actingAs($viewer);

        $this->postJson("/api/databases/{$this->db->id}/export", ['keep_days' => 7])->assertForbidden();
    });
});

describe('deleting expired exports', function () {

    it('removes an export and its file once its date has passed, and logs it', function () {
        File::ensureDirectoryExists(config('server.databases.export_dir'));
        $expired = optionsExport(['status' => ExportStatus::Completed, 'file' => 'old.sql.gz', 'expires_at' => now()->subMinute()]);
        file_put_contents(config('server.databases.export_dir').'/old.sql.gz', 'x');

        $this->artisan('exports:prune-expired')->assertExitCode(0);

        expect(DatabaseExport::find($expired->id))->toBeNull()
            ->and(file_exists(config('server.databases.export_dir').'/old.sql.gz'))->toBeFalse()
            ->and(ActivityLog::where('type', 'database')->where('action', 'export_expired')->exists())->toBeTrue();
    });

    it('leaves exports that are not due, kept forever, or still being written', function () {
        $notDue = optionsExport(['status' => ExportStatus::Completed, 'expires_at' => now()->addHour()]);
        $forever = optionsExport(['status' => ExportStatus::Completed, 'expires_at' => null]);
        $running = optionsExport(['status' => ExportStatus::Running, 'expires_at' => now()->subDay()]);

        $this->artisan('exports:prune-expired')->assertExitCode(0);

        expect(DatabaseExport::pluck('id')->sort()->values()->all())
            ->toBe(collect([$notDue->id, $forever->id, $running->id])->sort()->values()->all());
    });

    it('runs every hour, so a file goes within the hour it was promised to', function () {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'exports:prune-expired'));

        expect($event)->not->toBeNull()->and($event->expression)->toBe('0 * * * *');
    });
});

describe('compressed exports', function () {

    it('gzips a user SQL export, and it unpacks to exactly the dump', function () {
        $export = app(ExportDatabase::class)->execute($this->db, optionsExport());
        $path = config('server.databases.export_dir').'/'.$export->file;

        expect($export->file)->toEndWith('.sql.gz')
            ->and(gzdecode(file_get_contents($path)))->toBe($this->dumped)
            ->and($export->size_bytes)->toBe(filesize($path))
            ->and($export->size_bytes)->toBeLessThan(strlen($this->dumped))
            // The plain dump is gone, not left beside it.
            ->and(glob(config('server.databases.export_dir').'/*'))->toBe([$path]);
    });

    it("keeps an import's safety copy plain, because it is loaded straight back", function () {
        $export = app(ExportDatabase::class)->execute($this->db, optionsExport(['purpose' => DatabaseExport::PURPOSE_IMPORT_SAFETY]));

        expect($export->file)->toEndWith('.sql')
            ->and(file_get_contents(config('server.databases.export_dir').'/'.$export->file))->toBe($this->dumped);
    });

    it('does not gzip a MongoDB archive twice', function () {
        $mongo = Database::create(['name' => 'docs', 'engine' => 'mongodb']);

        $export = app(ExportDatabase::class)->execute($mongo, optionsExport(['database_id' => $mongo->id, 'engine' => 'mongodb']));

        expect($export->file)->toEndWith('.archive.gz')->not->toEndWith('.gz.gz');
    });

    it('keeps the plain dump when it cannot be compressed', function () {
        // A directory where the .gz would go: gzopen fails, as on a full disk.
        $dir = config('server.databases.export_dir');
        File::ensureDirectoryExists($dir);
        $real = app(ExportDatabase::class);

        Process::fake(function ($process) {
            foreach ((array) $process->command as $arg) {
                if (str_starts_with($arg, '--result-file=')) {
                    $path = substr($arg, strlen('--result-file='));
                    file_put_contents($path, $this->dumped);
                    mkdir($path.'.gz');
                }
            }

            return Process::result(output: '1');
        });

        $export = $real->execute($this->db, optionsExport());

        expect($export->status)->toBe(ExportStatus::Completed)
            ->and($export->file)->toEndWith('.sql')
            ->and(file_get_contents($dir.'/'.$export->file))->toBe($this->dumped);
    });
});

it('adds and removes its columns cleanly', function () {
    $migration = require database_path('migrations/2026_10_26_000002_add_export_expiry_and_import_safety_copy.php');

    $migration->down();
    expect(Schema::hasColumn('database_exports', 'expires_at'))->toBeFalse()
        ->and(Schema::hasColumn('database_imports', 'safety_copy'))->toBeFalse();

    $migration->up();
    expect(Schema::hasColumn('database_exports', 'expires_at'))->toBeTrue()
        ->and(Schema::hasColumn('database_imports', 'safety_copy'))->toBeTrue();
});
