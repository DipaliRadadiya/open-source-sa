<?php

use App\Actions\Server\Database\ExportDatabase;
use App\Enums\ExportStatus;
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
 * Export options (Dipali, 2026-10-26): a user's SQL export is gzipped, and an
 * export stays until the user deletes it — nothing deletes one on a schedule.
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

describe('an export is the user\'s to delete', function () {

    beforeEach(fn () => Queue::fake());

    /*
     * Dipali, 2026-10-26: exports are not deleted on a schedule. A choice of
     * days was built and taken back the same day; an old client still sending
     * one gets an ordinary export that stays until the user deletes it.
     */
    it('keeps every export until the user deletes it, whatever a client sends', function () {
        $this->postJson("/api/databases/{$this->db->id}/export", ['keep_days' => 1])
            ->assertStatus(202)
            ->assertJsonMissingPath('export.expires_at');

        expect(Schema::hasColumn('database_exports', 'expires_at'))->toBeFalse();
    });

    it('has no scheduled job that deletes exports, safety copies included', function () {
        // Dipali, 2026-10-26: an import's safety copy is the user's to delete
        // too; RC-14's week-long removal was taken back.
        $commands = collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->command);

        expect($commands->filter(fn (string $c) => str_contains($c, 'exports:prune')))->toBeEmpty();
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
    $add = require database_path('migrations/2026_10_26_000002_add_export_expiry_and_import_safety_copy.php');
    $drop = require database_path('migrations/2026_10_26_000003_drop_expires_at_from_database_exports.php');

    $drop->down();
    expect(Schema::hasColumn('database_exports', 'expires_at'))->toBeTrue();
    $drop->up();
    expect(Schema::hasColumn('database_exports', 'expires_at'))->toBeFalse();

    // The earlier migration still runs in order on a fresh install: down
    // with the column already dropped by its successor is re-added first.
    $drop->down();
    $add->down();
    expect(Schema::hasColumn('database_imports', 'safety_copy'))->toBeFalse();
    $add->up();
    $drop->up();
    expect(Schema::hasColumn('database_imports', 'safety_copy'))->toBeTrue()
        ->and(Schema::hasColumn('database_exports', 'expires_at'))->toBeFalse();
});
