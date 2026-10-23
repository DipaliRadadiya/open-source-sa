<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One thing the sync found, and what became of it.
 *
 * This is both the live progress feed and the history. The frontend polls for
 * items after a cursor and appends them, which is the same shape deployments
 * already use — a sync that only reported a final total would tell someone
 * watching a 200-site server nothing for several minutes.
 *
 * `skipped` and `failed` are first-class rather than omissions: a migration
 * that quietly leaves something out is exactly what this feature exists to
 * stop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_run_id')->constrained()->cascadeOnDelete();

            // system_user | ssh_key | application | database | …
            $table->string('resource_type');

            // The natural key on the server — a username, a domain, a database
            // name. What makes a re-run idempotent.
            $table->string('resource_key');

            // found (preview) | adopted | skipped | failed
            $table->string('action');

            // The row this became, when it became one.
            $table->nullableMorphs('model');

            // How sure the discovery is, and why — a site type read off disk
            // is a guess with evidence, not a fact.
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->json('evidence')->nullable();

            // Why it was skipped or how it failed. Never null for those.
            $table->string('reason')->nullable();

            $table->timestamps();

            // The poll query: everything for this run after a cursor.
            $table->index(['sync_run_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_items');
    }
};
