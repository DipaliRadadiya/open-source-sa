<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per file-manager compress or extract.
 *
 * Archiving used to run inside the request, against a 60 s ceiling that every
 * file operation shared. Selecting 110 GB therefore could not work: `tar` was
 * killed at the minute mark, which left a partial archive on disk, so the
 * obvious retry was refused by the does-it-already-exist guard and the user
 * was told about a filename collision for a problem that was about size.
 *
 * The same mistake `database_exports` was created to fix, and this table is
 * the same answer: somewhere to say "this is running" while it runs, and
 * somewhere to record why it stopped if it does.
 *
 * Rows are kept after completion, but they are not the record of the artefact
 * the way an export row is — the archive lands in the user's own tree and is
 * visible in the file browser. This exists to answer "is something happening"
 * and "why did it fail".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_archive_jobs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('application_id')->constrained()->cascadeOnDelete();

            // 'compress' or 'extract'. Both share this table because they
            // share every property that matters here: long, cancellable only
            // by failing, and producing something on disk.
            $table->string('operation');

            // Both relative to the document root, as everything the file
            // manager stores is.
            //
            // compress: `sources` are the files selected, `target` is the
            // archive being written. extract: `sources` is the one archive,
            // `target` is the directory it unpacks into.
            //
            // `target` is also what the unique lock is keyed on, which is why
            // it is the written-to path in both cases: two jobs writing one
            // archive produce a corrupt file, and two extracts into one
            // directory race over the same entries.
            $table->json('sources');
            $table->string('target');

            $table->string('status');

            // A stable code, not a sentence — the wording is built at read
            // time in the viewer's locale, the rule the activity log and the
            // export table both already follow. A message stored in English
            // is a message that cannot be translated later.
            $table->string('reason')->nullable();

            // Correlates with the server-ops log, so a failure can be traced
            // without putting command output in front of the user.
            $table->string('reference')->nullable();

            // Bytes written so far, for compress. Real bytes on disk, read by
            // statting the output — not a percentage inferred from something
            // tar does not report. Zero for extract, which has no equivalent.
            $table->unsignedBigInteger('size_bytes')->default(0);

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // The panel polls for in-flight rows belonging to one application,
            // which is exactly this index.
            $table->index(['application_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_archive_jobs');
    }
};
