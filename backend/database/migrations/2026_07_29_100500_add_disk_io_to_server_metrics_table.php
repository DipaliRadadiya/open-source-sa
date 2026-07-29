<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds disk throughput to the 5-min sample, alongside the network rate it
     * sits next to on the dashboard.
     *
     * Additive rather than folded into the create migration: that convention
     * assumed a table nobody had data in yet, and this one is being written to
     * on a shared development database. Adding columns is safe there; a
     * rebuild is not.
     */
    public function up(): void
    {
        Schema::table('server_metrics', function (Blueprint $table) {
            // Bytes/second at sample time, matching net_in / net_out.
            $table->unsignedBigInteger('disk_read')->default(0)->after('net_out');
            $table->unsignedBigInteger('disk_write')->default(0)->after('disk_read');
        });
    }

    public function down(): void
    {
        Schema::table('server_metrics', function (Blueprint $table) {
            $table->dropColumn(['disk_read', 'disk_write']);
        });
    }
};
