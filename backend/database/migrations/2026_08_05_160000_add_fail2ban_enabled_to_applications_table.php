<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            // No per-app tuning knobs in v1 — sane fixed defaults only,
            // matching the "ship the simple version first" rule this
            // project has followed all week.
            $table->boolean('fail2ban_enabled')->default(false)->after('waf_categories');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('fail2ban_enabled');
        });
    }
};
