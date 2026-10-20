<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per staging push or update-from-live (RC-16, 2026-10-20).
 *
 * Both ran inside the request; nginx stops waiting after 300 s, so a large site
 * (or a push that backs up first) answered 504 while the copy carried on, and
 * nothing could say afterwards whether it was still running or how it ended.
 * They now run on the queue and this row is that answer.
 *
 * `error` holds the body the old synchronous endpoint would have answered with
 * (`message`, `code`/`reason`, `reference`, `backup_id`, …) and `error_status`
 * its HTTP status, so a client reads a failed run the way it read the response.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staging_runs', function (Blueprint $table) {
            $table->id();
            // The live site, which both directions are addressed by.
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staging_application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->string('kind', 8);   // push | pull
            $table->string('mode', 16);  // files | database | full
            $table->boolean('backup')->default(false);
            $table->string('status', 16); // queued | running | succeeded | failed
            $table->json('error')->nullable();
            $table->unsignedSmallInteger('error_status')->nullable();
            $table->foreignId('backup_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['application_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staging_runs');
    }
};
