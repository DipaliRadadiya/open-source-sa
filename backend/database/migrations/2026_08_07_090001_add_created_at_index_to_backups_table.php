<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index the column the new date-range filter scans.
     *
     * `backups` is the one table here that grows without bound — a row per
     * site per night, forever, and retention prunes the artefacts on the
     * destination rather than this history. A range query over an unindexed
     * `created_at` is a full scan that gets measurably slower every night.
     */
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
        });
    }
};
