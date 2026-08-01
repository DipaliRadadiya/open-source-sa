<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per database export.
 *
 * The dump used to run inside the request. A real database takes longer than
 * nginx's `fastcgi_read_timeout`, so the browser was shown a failure while
 * mysqldump carried on and eventually wrote a perfectly good file — a dump
 * nobody could find, because the only way to download one was to already know
 * its generated filename.
 *
 * This table is what makes the work queueable: somewhere to report progress
 * while it runs, and somewhere the finished file is still listed afterwards.
 *
 * Unlike `runtime_installs`, a completed row is **kept**. That table can delete
 * on success because the filesystem then answers the question better than it
 * could; here the row *is* the record of which database a file came from, who
 * asked for it, and when — none of which the file itself knows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_exports', function (Blueprint $table) {
            $table->id();

            // Nullable, and the name is copied alongside it: dropping a
            // database must not erase the evidence that a dump of it exists,
            // and someone looking at a stale export needs to know what it was
            // of even after the source is gone.
            $table->foreignId('database_id')->nullable()->constrained()->nullOnDelete();
            $table->string('database_name');
            $table->string('engine');

            // Null until the dump succeeds. A queued or failed export has no
            // file, and an empty string would pretend otherwise.
            $table->string('file')->nullable();

            $table->string('status');

            // A stable code, not a sentence — the wording is built at read time
            // in the viewer's locale, the same rule the activity log follows.
            $table->string('reason')->nullable();

            // Correlates with the server-ops log so a failure can be traced
            // without putting command output in front of the user.
            $table->string('reference')->nullable();

            $table->unsignedBigInteger('size_bytes')->default(0);

            // Who asked. Null means the system did — nothing schedules exports
            // today, but backups will, and the column should not have to change
            // when they do.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // The list is newest-first and the status poll asks for in-flight
            // rows; both read this.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_exports');
    }
};
