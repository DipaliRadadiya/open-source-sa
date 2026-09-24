<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The exact crontab line an adopted job was read from.
 *
 * A job Server Sync finds in a user's crontab lives inside a file the panel
 * does not own, so there is no path to remove when the panel takes it over.
 * Without the line, the first edit wrote the panel's own copy and left the
 * crontab entry running: the job ran twice (found on a real server,
 * 2026-09-24). `source_path` says where (`crontab:<user>`), this says what.
 *
 * Nullable and additive: every job created by the panel, and every job adopted
 * from /etc/cron.d, has no line and needs none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cronjobs', function (Blueprint $table): void {
            $table->text('source_line')->nullable()->after('source_path');
        });
    }

    public function down(): void
    {
        Schema::table('cronjobs', function (Blueprint $table): void {
            $table->dropColumn('source_line');
        });
    }
};
