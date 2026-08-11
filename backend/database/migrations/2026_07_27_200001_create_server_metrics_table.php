<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per 5-min sample for the 24h charts. The collector prunes
        // rows older than the retention window, so this table stays bounded
        // (~288 rows) and never grows.
        Schema::create('server_metrics', function (Blueprint $table) {
            $table->id();
            $table->decimal('cpu_percent', 5, 2)->default(0);
            $table->decimal('memory_percent', 5, 2)->default(0);
            $table->decimal('swap_percent', 5, 2)->default(0);
            $table->decimal('disk_percent', 5, 2)->default(0);
            $table->decimal('load_1', 8, 2)->default(0);
            $table->decimal('load_5', 8, 2)->default(0);
            $table->decimal('load_15', 8, 2)->default(0);
            // Bytes/second at sample time.
            $table->unsignedBigInteger('net_in')->default(0);
            $table->unsignedBigInteger('net_out')->default(0);
            // Disk throughput, alongside the network rate it sits next to on
            // the dashboard.
            $table->unsignedBigInteger('disk_read')->default(0);
            $table->unsignedBigInteger('disk_write')->default(0);
            $table->timestamp('sampled_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_metrics');
    }
};
