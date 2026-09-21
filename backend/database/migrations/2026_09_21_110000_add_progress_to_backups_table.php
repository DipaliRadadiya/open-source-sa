<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a running backup is actually doing.
 *
 * A backup row recorded `status` and a step name and nothing else, so for the
 * entire length of an upload — three and a half hours, on the run that prompted
 * this — the panel showed `upload_artifact` and could not distinguish a healthy
 * transfer from a dead socket. It was reported as a hang, and diagnosing it
 * needed `strace` and `/proc/<pid>/fdinfo` on the box. A progress figure the
 * panel already had would have answered it in a glance.
 *
 * Three columns, because one is not enough to tell the two apart:
 *
 * - `bytes_transferred` / `bytes_total` are the fraction, and the second is
 *   nullable because a step that cannot know its own total (a dump that is
 *   still being written) should say so rather than invent a denominator and
 *   render a progress bar that lies.
 * - `progress_at` is the heartbeat, and it is the load-bearing one. *Bytes* are
 *   what separates slow from stuck — a transfer moving at 200 KB/s is alive,
 *   one that has moved nothing for twenty minutes is not — and no wall-clock
 *   timeout can make that distinction. {@see StaleBackupReaper} reads this
 *   instead of guessing from `started_at`, which is why a crashed run no longer
 *   locks its target out for the job's full timeout.
 *
 * All nullable and all additive: every existing row keeps meaning exactly what
 * it meant, and a reader that finds nulls is looking at a backup that ran
 * before this shipped, not at a broken one. Nothing backfills, because there is
 * no honest value to backfill with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->unsignedBigInteger('bytes_transferred')->nullable()->after('size_bytes');
            $table->unsignedBigInteger('bytes_total')->nullable()->after('bytes_transferred');
            $table->timestamp('progress_at')->nullable()->after('bytes_total');
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->dropColumn(['bytes_transferred', 'bytes_total', 'progress_at']);
        });
    }
};
