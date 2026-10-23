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

            // Whether the thing being installed was absent when the install was
            // *requested*, recorded once and never revised.
            //
            // Installers ask the box "is this already here?" to decide whether the
            // configuration is theirs to write. On a first attempt that is the right
            // question. On a retry after a part-finished attempt it is the wrong
            // one: the package is now present, so the installer concludes somebody
            // else put it there and skips the setup — which for MongoDB means
            // leaving authentication off on a database the panel believes it just
            // installed successfully.
            //
            // Nullable, because a row with no recorded answer must not have one
            // invented — that is the very fact this exists to stop being guessed. A
            // null means "nobody recorded it", and the installer falls back to
            // asking the box.
            $table->boolean('was_absent')->nullable();

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
            // When a worker picked an install up (FS-B1).
            //
            // One worker runs installs and site setups in turn, so an install can
            // wait minutes for its turn — and read as "installing" the whole time.
            // Null means still queued.
            $table->timestamp('picked_up_at')->nullable();
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
