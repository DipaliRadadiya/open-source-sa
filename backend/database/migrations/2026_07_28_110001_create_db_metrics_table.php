<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bounded 5-min DB metric samples (per engine) powering the Query Monitor
     * chart. `queries` is the cumulative counter — QPS is the delta between
     * consecutive samples. Pruned to 24h (~288 rows/engine) like server_metrics.
     */
    public function up(): void
    {
        Schema::create('db_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('engine');
            $table->unsignedBigInteger('queries')->default(0);
            $table->unsignedInteger('connections')->default(0);
            $table->unsignedInteger('threads_running')->default(0);
            $table->timestamp('sampled_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('db_metrics');
    }
};
