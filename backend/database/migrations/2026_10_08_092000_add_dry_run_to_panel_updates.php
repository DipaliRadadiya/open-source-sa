<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a panel update run was a dry run (FS-C2).
 *
 * A finished dry run read, after a reload, as "Updated to version 1.0.17":
 * the row looked exactly like a real update. Earlier rows are taken as real
 * runs — nothing recorded which they were.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('panel_updates', function (Blueprint $table): void {
            $table->boolean('dry_run')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('panel_updates', function (Blueprint $table): void {
            $table->dropColumn('dry_run');
        });
    }
};
