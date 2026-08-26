<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an in-flight runtime install lives.
 *
 * Versions and extensions are detected from disk, not stored — so a version
 * apt has not finished installing has nowhere to exist, and the screen cannot
 * show it. This table holds only what the disk cannot answer: what is running
 * right now, and what failed last time.
 *
 * Deliberately not a mirror of installed state. A successful install deletes
 * its row and the filesystem becomes the truth again, so the two can never
 * drift into disagreeing about what is installed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runtime_installs', function (Blueprint $table) {
            $table->id();

            // php | node — the same shape serves both, and any runtime added
            // later gets progress for free.
            $table->string('runtime');
            $table->string('version');

            // The extension being installed, or '' for the runtime version
            // itself. Empty rather than null so the unique index actually
            // holds: in SQL, NULL never equals NULL, so a nullable column
            // would let duplicate "install PHP 8.3" rows through.
            $table->string('extension')->default('');

            $table->string('status');

            // Progress derived from installer output; output is bounded on write.
            $table->string('current_step')->nullable();
            $table->text('output')->nullable();

            // A stable code (`package_not_found`, `apt_lock`, `worker`, …).
            // The human sentence is built from this at read time in the
            // viewer's locale; raw stderr is never stored here — it stays in
            // the server-ops log under `reference`.
            $table->string('reason')->nullable();
            $table->string('reference')->nullable();

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['runtime', 'version', 'extension']);
            $table->index(['runtime', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runtime_installs');
    }
};
