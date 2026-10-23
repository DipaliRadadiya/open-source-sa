<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `applications.insighthub_id` becomes `log_monitoring_suite_id`.
 *
 * The addon launched as InsightHub and is now Log Monitoring Suite, binary
 * and all (approved by Dipali, 2026-10-23). The column keeps its meaning: the
 * id the addon gave the site, which every report is asked by. Renamed in place
 * rather than added and copied, so any site already registered stays
 * registered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->renameColumn('insighthub_id', 'log_monitoring_suite_id');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->renameColumn('log_monitoring_suite_id', 'insighthub_id');
        });
    }
};
