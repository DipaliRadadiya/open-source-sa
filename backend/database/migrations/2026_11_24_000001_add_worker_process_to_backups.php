<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Which worker process runs a backup, so a run whose worker has died can be
 * cleared as soon as it has, instead of after the 65-minute guess (QA,
 * 2026-11-24). Null for rows from before, which keep the old rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->unsignedInteger('worker_pid')->nullable();
            // The process's start time from /proc, so a PID the kernel has
            // handed to a new process is not mistaken for the worker.
            $table->string('worker_started', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn(['worker_pid', 'worker_started']);
        });
    }
};
