<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disk_cleaner_schedules', function (Blueprint $table) {
            $table->id();
            // Singleton row: the automatic-cleaner profile the scheduler reads.
            $table->boolean('enabled')->default(false);
            $table->string('frequency')->default('weekly'); // hourly|daily|weekly|monthly
            // Safe categories to clean unattended.
            $table->json('categories')->nullable();
            // Clean only when disk usage is at/above this % (null = always when due).
            $table->unsignedTinyInteger('threshold_percent')->nullable();
            $table->boolean('notify')->default(false);
            // Last time a scheduled run actually executed (drives the due check).
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disk_cleaner_schedules');
    }
};
