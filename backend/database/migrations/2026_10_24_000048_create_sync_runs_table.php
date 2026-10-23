<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One press of Sync.
 *
 * A migrated server holds users, sites, databases and cron jobs the panel
 * knows nothing about, and re-entering them by hand is the reason people put
 * migrations off. A run reads the box, records what it found line by line, and
 * — in `apply` mode — writes the panel rows.
 *
 * The run is kept rather than discarded because "what did we import when we
 * migrated this box" is a question people ask months later, and because a
 * `preview` is only useful if its result outlives the request that made it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // preview reads and adopts nothing; apply writes the rows.
            $table->string('mode')->default('preview');
            $table->string('status')->default('pending');

            // What the caller asked for: `only` narrows the resource types,
            // `include_firewall` opts in to the one class of adoption a later
            // sync could undo.
            $table->json('options')->nullable();

            // Per-type counts, so a finished run renders without walking
            // every item.
            $table->json('totals')->nullable();

            $table->string('reference')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
