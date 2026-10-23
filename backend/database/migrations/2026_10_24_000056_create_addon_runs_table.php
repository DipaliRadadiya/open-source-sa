<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addon commands Central runs that can outlast a request — a plugin update,
 * a core update, a search-replace over a large database. The request answers
 * 202 with the run, Central polls it, and the row holds what the addon said.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addon_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('application_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('addon', 32);
            $table->string('command', 64);
            $table->string('status', 16)->default('queued');
            $table->json('arguments');

            // What a queued addon command reads on stdin -- a blueprint with its
            // script, Object Cache Pro's Redis credentials. Stored encrypted (the
            // model casts it), because it is exactly what must not sit in the
            // clear anywhere.
            $table->text('input')->nullable();

            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('result')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addon_runs');
    }
};
