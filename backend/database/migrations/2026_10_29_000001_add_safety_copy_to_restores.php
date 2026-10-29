<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a restore takes a copy of the application before replacing it
     * (support list, 2026-10-29: the user's choice). True on every existing
     * row — they all did.
     */
    public function up(): void
    {
        Schema::table('restores', function (Blueprint $table) {
            $table->boolean('safety_copy')->default(true)->after('safety_backup_id');
        });
    }

    public function down(): void
    {
        Schema::table('restores', function (Blueprint $table) {
            $table->dropColumn('safety_copy');
        });
    }
};
