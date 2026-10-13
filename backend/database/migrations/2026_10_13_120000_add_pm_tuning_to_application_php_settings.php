<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // v7 stored these per site; v8 derived them from pm_max_children.
        // Null keeps deriving them, so no existing pool changes (Dipali,
        // 2026-10-13: keep v7's own numbers when a site has them).
        Schema::table('application_php_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('pm_start_servers')->nullable()->after('pm_max_requests');
            $table->unsignedSmallInteger('pm_min_spare_servers')->nullable()->after('pm_start_servers');
            $table->unsignedSmallInteger('pm_max_spare_servers')->nullable()->after('pm_min_spare_servers');
            $table->unsignedInteger('pm_process_idle_timeout')->nullable()->after('pm_max_spare_servers');
            $table->unsignedSmallInteger('pm_max_spawn_rate')->nullable()->after('pm_process_idle_timeout');
        });
    }

    public function down(): void
    {
        Schema::table('application_php_settings', function (Blueprint $table): void {
            $table->dropColumn(['pm_start_servers', 'pm_min_spare_servers', 'pm_max_spare_servers', 'pm_process_idle_timeout', 'pm_max_spawn_rate']);
        });
    }
};
