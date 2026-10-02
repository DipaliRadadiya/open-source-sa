<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addon commands Central runs that can outlast a request — a plugin update,
 * a core update, a search-replace over a large database. The request answers
 * 202 with the run, Central polls it, and the row holds what the addon said.
 *
 * Plus `applications.insighthub_id`: the InsightHub toolkit numbers sites
 * itself, and every report is asked by that number.
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
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('result')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->unsignedBigInteger('insighthub_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('insighthub_id');
        });

        Schema::dropIfExists('addon_runs');
    }
};
