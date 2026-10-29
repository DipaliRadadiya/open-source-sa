<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a backup that died without reporting had got to, and why it most
 * likely died (2026-10-29, Dipali: support could not tell a user anything
 * after clearing a stuck backup). `reason` keeps `abandoned` / `crashed`;
 * these two only make the message say more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->string('stopped_step', 64)->nullable()->after('reason');
            $table->string('stopped_cause', 32)->nullable()->after('stopped_step');
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn(['stopped_step', 'stopped_cause']);
        });
    }
};
