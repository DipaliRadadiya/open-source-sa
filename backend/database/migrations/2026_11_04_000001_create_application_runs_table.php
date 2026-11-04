<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per install and per container redeploy of an application (D3/D5,
 * 2026-11-04).
 *
 * Both ran with no record of their own: an install only moved the
 * application's `status`, and Pull and redeploy ran inside one HTTP request and
 * changed nothing at all. So Background jobs could not list either, and a page
 * reloaded mid-pull showed a site with nothing happening to it. The app's own
 * columns cannot hold the answer: a later failed git deploy sets `status` to
 * `failed`, and that must not rewrite a finished install as a failed one.
 *
 * `kind` is `install` or `container_redeploy`. Bounded per application and kind
 * on write (ApplicationRuns::open), like `deployments`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 32);
            $table->string('status', 16)->default('queued');
            $table->string('failed_step')->nullable();
            $table->string('failed_reason')->nullable();
            $table->string('reference')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['application_id', 'kind', 'status']);
            $table->index(['kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_runs');
    }
};
