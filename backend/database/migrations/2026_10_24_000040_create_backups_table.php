<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per backup attempt, of a site or of a database on its own.
 *
 * `backups.database_id` is `nullOnDelete`, and `backups` carries the name and
 * engine of what it was taken from. Deleting a database leaves its backups
 * listed, downloadable and restorable into another database ("archived"),
 * rather than deleting the rows and stranding the archives in the bucket with
 * nothing pointing at them — which is what deleting a site does. For the same
 * reason `backup_target_id` is nullable: the schedule goes with the database,
 * its history does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table): void {
            $table->id();

            // A name for the archive that no other backup can ever take.
            //
            // The object key used to be `backups/{domain}/{date}/{backup_id}`,
            // and `backup_id` is an autoincrement that only means anything
            // inside one panel's database. Reinstall the panel, or restore its
            // database from an older dump, and the counting starts at 1 again —
            // so the next backup writes to the key an existing archive already
            // occupies, and an S3 PUT overwrites. The old backup is destroyed
            // silently, at exactly the moment somebody reinstalling would want
            // it. Two panels sharing a destination and a prefix collide the
            // same way.
            $table->uuid('uid')->nullable()->unique();

            $table->foreignId('backup_target_id')->nullable()->constrained()->cascadeOnDelete();

            // Where each archive was actually written.
            //
            // A target's destination is editable, so reaching the destination
            // through `backup_target_id` meant repointing a target moved
            // *history*: older backups started resolving to a bucket their
            // archive was never in, breaking download, delete and retention
            // pruning and showing the wrong destination name against them. The
            // key is recorded on the row precisely so nothing recomputes it;
            // the destination is the other half of an address.
            //
            // Nullable because a row whose target or destination has already
            // gone has no answer. `restrictOnDelete`, matching `backup_targets`:
            // a destination that still holds archives cannot be deleted out
            // from under them — nulling the column would leave rows claiming an
            // archive at an address nothing can resolve.
            $table->foreignId('storage_destination_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('application_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('database_id')->nullable()->constrained()->nullOnDelete();

            // What the backup was taken from, kept after it is gone.
            $table->string('subject_name')->nullable();
            $table->string('subject_engine', 32)->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 16);
            // A backup taken automatically just before a restore overwrites
            // the application. It is the only way back from a restore that
            // turns out to be wrong, so it must never be deleted to make room
            // for a scheduled one — retention skips it entirely.
            $table->boolean('is_safety')->default(false)->index();
            $table->string('status', 16)->index();
            $table->json('manifest')->nullable();
            $table->string('reason')->nullable();

            // Why a failed backup could not reach its storage. `reason` is the
            // step — `upload_artifact` — which is where, not why; the cause
            // (wrong keys, no permission, a bucket that is gone) would
            // otherwise only be in the server log. Same categories the
            // destination test already reports.
            $table->string('error_class', 64)->nullable();
            $table->uuid('reference')->nullable()->index();
            $table->string('log_key', 40)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            // What a running backup is actually doing.
            //
            // A row that records `status` and a step name and nothing else
            // cannot distinguish a healthy upload from a dead socket for as
            // long as the upload lasts; a progress figure the panel already has
            // answers it at a glance.
            //
            // `bytes_transferred` / `bytes_total` are the fraction, and the
            // second is nullable because a step that cannot know its own total
            // (a dump that is still being written) should say so rather than
            // invent a denominator and render a progress bar that lies.
            //
            // `progress_at` is the heartbeat, and it is the load-bearing one.
            // *Bytes* are what separates slow from stuck — a transfer moving at
            // 200 KB/s is alive, one that has moved nothing for twenty minutes
            // is not — and no wall-clock timeout can make that distinction.
            // {@see StaleBackupReaper} reads this instead of guessing from
            // `started_at`, so a crashed run no longer locks its target out for
            // the job's full timeout. Null means the backup ran before progress
            // was recorded, not that it is broken.
            $table->unsignedBigInteger('bytes_transferred')->nullable();
            $table->unsignedBigInteger('bytes_total')->nullable();
            $table->timestamp('progress_at')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['application_id', 'status']);
            $table->index(['database_id', 'status']);
            // This is the one table here that grows without bound — a row per
            // site per night, forever, since retention prunes the artefacts on
            // the destination rather than this history. A date-range query
            // over an unindexed `created_at` is a full scan that gets
            // measurably slower every night.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
