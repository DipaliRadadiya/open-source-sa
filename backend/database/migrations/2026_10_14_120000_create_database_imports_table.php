<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per import of an uploaded file into a database (2026-10-14).
 *
 * An import replaces a database's contents, so the row is also the record of
 * what it replaced them with, who asked, and — through `safety_export_id` —
 * where the copy taken just before lives. Kept after it finishes for the same
 * reason export rows are.
 *
 * `database_exports.purpose` marks that copy, so the Exports list can say
 * "automatic backup before import" instead of passing it off as the user's own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_imports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('database_id')->nullable()->constrained()->nullOnDelete();
            // Copied, so the row still says what it was of after the database is gone.
            $table->string('database_name');
            $table->string('engine');

            // As the uploader named it — shown back, never used as a path.
            $table->string('original_name');
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedBigInteger('received_bytes')->default(0);

            $table->string('status');
            // Where a running import is: backing_up, importing, rolling_back.
            $table->string('step')->nullable();
            $table->string('reason')->nullable();
            $table->string('reference')->nullable();
            // Where in the file a refusal was found, when it was one line.
            $table->unsignedInteger('line')->nullable();

            $table->foreignId('safety_export_id')->nullable()->constrained('database_exports')->nullOnDelete();
            // True when the import failed and the safety copy was loaded back.
            $table->boolean('restored')->default(false);

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['database_id', 'status']);
        });

        Schema::table('database_exports', function (Blueprint $table) {
            // null for an export the user asked for; `import_safety` for the
            // copy an import takes before replacing anything.
            $table->string('purpose')->nullable()->after('engine');
        });
    }

    public function down(): void
    {
        Schema::table('database_exports', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });

        Schema::dropIfExists('database_imports');
    }
};
