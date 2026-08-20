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
 * An export copies an entire database off the server and writes a full copy to
 * disk. It had none of the guards its neighbours have: `optimize` and `repair`
 * two lines above in the same route file require `manage`, and every other
 * expensive operation in the panel is throttled and made unique.
 *
 * So a read-only database user could take a complete copy of every database,
 * download all of them, and — with nothing rate-limiting the button — fill the
 * disk while doing it.
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

    /*
     * The heart of it. Reading the list of databases is not the same as being
     * handed their contents, and this endpoint hands over the contents.
     */
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

    /*
     * Downloading is the other half of the same exposure — the dump is only a
     * file on disk until somebody can fetch it. Matches the backup download,
     * which is on `manage` for the identical reason.
     */
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

    /*
     * Two mysqldump runs against the same database each write a full copy to
     * the same disk, on a queue with one worker, and the second produces the
     * same bytes as the first. Before this, a double-submit started both.
     */
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

    /*
     * The job is unique per database, so the lock has to be per database too.
     * One shared key would make every export in the panel queue behind every
     * other one.
     */
    it('locks per database rather than globally', function () {
        expect((new RunDatabaseExport(1, 7))->uniqueId())
            ->toBe('database-export-7')
            ->not->toBe((new RunDatabaseExport(1, 8))->uniqueId());
    });

    /*
     * Without an expiry the lock is taken with no TTL, so a worker killed
     * mid-dump would block that database's exports permanently — the exact
     * failure ExpiresUniqueLock exists to prevent, and the one the backup
     * feature shipped with.
     */
    it('gives the lock an expiry past the job\'s own timeout', function () {
        $job = new RunDatabaseExport(1, 1);

        expect($job->uniqueFor())->toBeGreaterThan($job->timeout);
    });
});

describe('a dump stranded by a killed worker', function () {

    beforeEach(function () {
        grantPermission($this->user, 'database', view: true, manage: true);
    });

    /*
     * The in-flight guard above is new, so this hazard is new with it: a row
     * nothing will ever finish would otherwise mean this database could never
     * be exported again.
     */
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
    // The job is unique per database, and a lock outliving the worker that held
    // it makes Laravel discard the next dispatch silently — no exception, no
    // failed_jobs row. The controller has already written a `queued` row and
    // answered 202, so the screen polls something that will never arrive.
    //
    // `export()` reaped these, which unblocks the database for whoever tries
    // again — but the reader watching the spinner has no reason to try. This is
    // the list the screen polls, so it is where a stranded row has to resolve.
    // Aged past the job's own timeout plus its grace, which is the only bound
    // this feature has for "nothing can still be working on it".
    grantPermission($this->user, 'database');

    $export = exportRow(ExportStatus::Queued, (int) ceil((new RunDatabaseExport(0, 0))->uniqueFor() / 60) + 1);

    $this->getJson('/api/databases/exports')->assertOk();

    expect($export->fresh()->status)->toBe(ExportStatus::Failed)
        ->and($export->fresh()->reason)->toBe('worker');
});

it('leaves a genuinely running export alone', function () {
    // The bound is the job's own timeout plus a grace. Reaping early would fail
    // a dump that is still writing, and the row is the only thing telling the
    // user it is.
    grantPermission($this->user, 'database');

    $export = exportRow(ExportStatus::Running, 0);

    $this->getJson('/api/databases/exports')->assertOk();

    expect($export->fresh()->status)->toBe(ExportStatus::Running);
});
