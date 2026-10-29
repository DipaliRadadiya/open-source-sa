<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a staging push/pull dumps the database it replaces first (the
     * "safety copies" on the Staging screen) — the user's choice since
     * 2026-10-29. True on every existing row: they all did.
     */
    public function up(): void
    {
        Schema::table('staging_runs', function (Blueprint $table) {
            $table->boolean('safety_copy')->default(true)->after('backup');
        });
    }

    public function down(): void
    {
        Schema::table('staging_runs', function (Blueprint $table) {
            $table->dropColumn('safety_copy');
        });
    }
};
