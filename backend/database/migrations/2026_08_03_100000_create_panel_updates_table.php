<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per requested panel update.
 *
 * Written before the job is dispatched, so the screen can show `pending` in
 * the seconds before a worker picks it up — without that an admin who clicks
 * "Update" sees nothing and clicks again, and the cache lock is the only
 * thing keeping two rows out of the table.
 *
 * Bounded on purpose: there is at most one panel update at a time, the row
 * itself is small, and an unbounded table would be a place for nothing to
 * accumulate — so there is no `output` column. A failed run's evidence lives
 * in the server-ops log under `reference`, exactly like the install rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel_updates', function (Blueprint $table) {
            $table->id();

            // Who clicked the button. Null is reserved for an admin that has
            // since been deleted — same rule as the activity log, same reason:
            // inventing an actor is a lie the audit log already refuses to tell.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // pending · running · succeeded · failed · rolled_back
            $table->string('status')->default('pending');

            // A short, stable code (`unsupported`, `lock_failed`, ...). The
            // exact human wording is built at read time from lang/, in the
            // viewer's locale — the same rule runtime installs follow, for
            // the same reason: the stored value travels across locales but the
            // text must not.
            $table->string('reason')->nullable();

            // The id the user can quote to support. Technical detail stays in
            // the log under this id; the row carries only the id itself.
            $table->string('reference')->nullable();

            // What `installed.commit_hash` said when this update was queued —
            // a snapshot, not a derived fact. "Where are we going from?" is
            // something a future rollback helper needs to know.
            $table->string('from_version')->nullable();
            $table->string('from_commit')->nullable();
            $table->string('to_version')->nullable();
            $table->string('to_commit')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            // The list is always "newest first"; the cache lock also asserts
            // there is only one in-flight row at a time.
            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_updates');
    }
};