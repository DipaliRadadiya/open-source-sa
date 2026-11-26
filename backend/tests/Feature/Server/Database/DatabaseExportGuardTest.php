<?php

use App\Enums\ExportStatus;
use App\Jobs\RunDatabaseExport;
use App\Models\Database;
use App\Models\DatabaseExport;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
 * An export copies a whole database to disk, so it needs the guards the other expensive
 * operations have: `manage` permission, one dump at a time, and unique jobs. Without them a
 * read-only user could download every database and fill the disk.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    Queue::fake();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->db = Database::create(['name' => 'shop', 'engine' => 'mysql']);
});

/** An export row for the shared database, aged by $minutes. */
function exportRow(ExportStatus $status, int $minutes = 0): DatabaseExport
{
    $export = DatabaseExport::create([
        'database_id' => test()->db->id,
        'database_name' => test()->db->name,
        'engine' => 'mysql',
        'status' => $status,
        'started_at' => now()->subMinutes($minutes),
    ]);

    return $export->forceFill(['created_at' => now()->subMinutes($minutes)])->save()
        ? $export->refresh()
        : $export;
}

describe('who may take a copy of a database', function () {

    // Reading the list of databases is not the same as being handed their contents.
    it('refuses to start an export for a read-only database user', function () {
        grantPermission($this->user, 'database');

        $this->postJson("/api/databases/{$this->db->id}/export")->assertForbidden();

        Queue::assertNothingPushed();
    });

    it('allows it for a user who may manage databases', function () {
        grantPermission($this->user, 'database', view: true, manage: true);

        $this->postJson("/api/databases/{$this->db->id}/export")->assertStatus(202);

        Queue::assertPushed(RunDatabaseExport::class);
    });

    // Downloading is the other half of the exposure; the backup download is on `manage` too.
    it('refuses the download to a read-only user but not the listing', function () {
        grantPermission($this->user, 'database');

        // Knowing a dump exists is fine; being handed it is not.
        $this->getJson('/api/databases/exports')->assertOk();
        $this->getJson('/api/databases/exports/shop-mysql-20260819-000000-abcdef.sql')->assertForbidden();
    });
});

describe('one dump at a time', function () {

    beforeEach(function () {
        grantPermission($this->user, 'database', view: true, manage: true);
    });

    // Two dumps of one database write the same bytes to the same disk; a double-submit must
    // not start both.
    it('refuses a second export while one is in flight', function () {
        exportRow(ExportStatus::Running);

        $this->postJson("/api/databases/{$this->db->id}/export")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => __('errors/database.export_already_running')]);

        Queue::assertNothingPushed();
    });

    it('counts a queued export, not only a running one', function () {
        exportRow(ExportStatus::Queued);

        $this->postJson("/api/databases/{$this->db->id}/export")->assertStatus(422);
    });

    it('does not block a different database', function () {
        exportRow(ExportStatus::Running);
        $other = Database::create(['name' => 'blog', 'engine' => 'mysql']);

        $this->postJson("/api/databases/{$other->id}/export")->assertStatus(202);
    });

    // A shared key would queue every export in the panel behind every other one.
    it('locks per database rather than globally', function () {
        expect((new RunDatabaseExport(1, 7))->uniqueId())
            ->toBe('database-export-7')
            ->not->toBe((new RunDatabaseExport(1, 8))->uniqueId());
    });

    // Without an expiry (see ExpiresUniqueLock) a worker killed mid-dump blocks that database's
    // exports forever.
    it('gives the lock an expiry past the job\'s own timeout', function () {
        $job = new RunDatabaseExport(1, 1);

        expect($job->uniqueFor())->toBeGreaterThan($job->timeout);
    });
});

describe('a dump stranded by a killed worker', function () {

    beforeEach(function () {
        grantPermission($this->user, 'database', view: true, manage: true);
    });

    // Otherwise a row nothing will ever finish would block this database's exports forever.
    it('is closed out, and the next export goes through', function () {
        $stranded = exportRow(ExportStatus::Running, 24 * 60);

        $this->postJson("/api/databases/{$this->db->id}/export")->assertStatus(202);

        expect($stranded->fresh()->status)->toBe(ExportStatus::Failed)
            ->and($stranded->fresh()->reason)->toBe('worker')
            ->and($stranded->fresh()->finished_at)->not->toBeNull();
    });

    it('leaves a dump alone while it could still be running', function () {
        $live = exportRow(ExportStatus::Running, 5);

        $this->postJson("/api/databases/{$this->db->id}/export")->assertStatus(422);

        expect($live->fresh()->status)->toBe(ExportStatus::Running);
    });

    it('closes one that never started, not just one that stalled midway', function () {
        // Dispatched while the queue was down: no worker ever touched it.
        $queued = exportRow(ExportStatus::Queued, 24 * 60);
        $queued->forceFill(['started_at' => null])->save();

        $this->postJson("/api/databases/{$this->db->id}/export")->assertStatus(202);

        expect($queued->fresh()->status)->toBe(ExportStatus::Failed);
    });

    it('has prose for the reason it writes, not a translation key', function () {
        $stranded = exportRow(ExportStatus::Running, 24 * 60);
        $this->postJson("/api/databases/{$this->db->id}/export")->assertStatus(202);

        expect($stranded->fresh()->message())
            ->not->toContain('database.export_failed')
            ->toContain('stopped unexpectedly');
    });
});

it('closes out a stranded export when the list is read, not only when one is started', function () {
    // A lock outliving its worker makes Laravel silently discard the next dispatch (no exception,
    // no failed_jobs row), yet a `queued` row was written and 202 answered. The screen polls this
    // list, so a stranded row must resolve here, not only when someone starts another export.
    // Aged past the job's timeout plus grace: the only bound for "nothing can still be working".
    grantPermission($this->user, 'database');

    $export = exportRow(ExportStatus::Queued, (int) ceil((new RunDatabaseExport(0, 0))->uniqueFor() / 60) + 1);

    $this->getJson('/api/databases/exports')->assertOk();

    expect($export->fresh()->status)->toBe(ExportStatus::Failed)
        ->and($export->fresh()->reason)->toBe('worker');
});

it('leaves a genuinely running export alone', function () {
    // Reaping before the job's timeout plus grace would fail a dump that is still writing.
    grantPermission($this->user, 'database');

    $export = exportRow(ExportStatus::Running, 0);

    $this->getJson('/api/databases/exports')->assertOk();

    expect($export->fresh()->status)->toBe(ExportStatus::Running);
});
