<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A second loopback port, for an application whose own process listens on
 * one more than `app_port` (FS-C22 #8).
 *
 * n8n's task-runner broker binds 127.0.0.1:5679 unless told otherwise, so a
 * second n8n on the server found it taken and exited on start (n8n 2.44:
 * "Task Broker's port 5679 is already in use", then `process.exit(1)`).
 * Allocated from the same range as `app_port` and unique for the same reason.
 *
 * Nullable, no backfill (new sites only, per the 2026-10-03 rule): an existing
 * n8n keeps the default it is running on, and null says exactly that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->unsignedInteger('internal_port')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropUnique(['internal_port']);
            $table->dropColumn('internal_port');
        });
    }
};
