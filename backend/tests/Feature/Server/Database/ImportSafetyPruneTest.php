<?php

use App\Enums\ExportStatus;
use App\Enums\ImportStatus;
use App\Models\Database;
use App\Models\DatabaseExport;
use App\Models\DatabaseImport;
use Illuminate\Support\Facades\File;

/*
 * RC-14 (frontend QA, 15 Oct; operator 2026-10-20: "remove after 7 days"):
 * every import kept its safety copy for ever — six imports, 81 MB, on the
 * database's own disk. Now a week, never the copy of an import that failed
 * and was not put back, and never one an import is still using.
 */
beforeEach(function () {
    $this->exportDir = sys_get_temp_dir().'/sv-oss-exports-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->exportDir);
    config(['server.databases.export_dir' => $this->exportDir]);
    $this->db = Database::create(['name' => 'ledger', 'engine' => 'mysql']);
});

afterEach(fn () => File::deleteDirectory($this->exportDir));

function safetyCopy(int $days, ?string $purpose = DatabaseExport::PURPOSE_IMPORT_SAFETY, ExportStatus $status = ExportStatus::Completed): DatabaseExport
{
    $file = 'ledger-'.bin2hex(random_bytes(3)).'.sql';
    file_put_contents(test()->exportDir.'/'.$file, '-- dump');

    $export = DatabaseExport::create([
        'database_id' => test()->db->id, 'database_name' => 'ledger', 'engine' => 'mysql',
        'purpose' => $purpose, 'status' => $status, 'file' => $file, 'size_bytes' => 7,
    ]);
    $export->forceFill(['created_at' => now()->subDays($days)])->save();

    return $export;
}

function importUsing(DatabaseExport $export, ImportStatus $status, bool $restored = false): void
{
    DatabaseImport::create([
        'database_id' => test()->db->id, 'database_name' => 'ledger', 'engine' => 'mysql',
        'original_name' => 'in.sql', 'size_bytes' => 1, 'status' => $status,
        'safety_export_id' => $export->id, 'restored' => $restored,
    ]);
}

it('removes a safety copy older than a week, with its file', function () {
    $old = safetyCopy(8);
    importUsing($old, ImportStatus::Completed);

    $this->artisan('exports:prune-import-safety')->assertSuccessful();

    expect(DatabaseExport::find($old->id))->toBeNull()
        ->and(is_file($this->exportDir.'/'.$old->file))->toBeFalse();
});

it('keeps one from the last week', function () {
    $recent = safetyCopy(6);

    $this->artisan('exports:prune-import-safety')->assertSuccessful();

    expect(DatabaseExport::find($recent->id))->not->toBeNull()
        ->and(is_file($this->exportDir.'/'.$recent->file))->toBeTrue();
});

it('keeps the copy of an import that failed and was not put back', function () {
    $copy = safetyCopy(30);
    importUsing($copy, ImportStatus::Failed, restored: false);

    $this->artisan('exports:prune-import-safety')->assertSuccessful();

    expect(DatabaseExport::find($copy->id))->not->toBeNull();
});

it('removes the copy of a failed import that was put back', function () {
    $copy = safetyCopy(30);
    importUsing($copy, ImportStatus::Failed, restored: true);

    $this->artisan('exports:prune-import-safety')->assertSuccessful();

    expect(DatabaseExport::find($copy->id))->toBeNull();
});

it('keeps the copy of an import still running', function () {
    $copy = safetyCopy(30);
    importUsing($copy, ImportStatus::Running);

    $this->artisan('exports:prune-import-safety')->assertSuccessful();

    expect(DatabaseExport::find($copy->id))->not->toBeNull();
});

it('never touches the user\'s own exports', function () {
    $mine = safetyCopy(90, purpose: null);

    $this->artisan('exports:prune-import-safety')->assertSuccessful();

    expect(DatabaseExport::find($mine->id))->not->toBeNull()
        ->and(is_file($this->exportDir.'/'.$mine->file))->toBeTrue();
});

it('leaves a safety copy that is still being written', function () {
    $writing = safetyCopy(30, status: ExportStatus::Running);

    $this->artisan('exports:prune-import-safety')->assertSuccessful();

    expect(DatabaseExport::find($writing->id))->not->toBeNull();
});

it('is scheduled daily', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('exports:prune-import-safety')->assertSuccessful();
});
