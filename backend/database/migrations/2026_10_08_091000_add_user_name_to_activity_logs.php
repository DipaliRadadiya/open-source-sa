<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who did it, kept after their account is deleted (FS-C22).
 *
 * `user_id` is nulled when a user is deleted, and a null user is how the log
 * says "the system did this" — so everything a deleted person had done was
 * re-attributed to the panel itself. Their username is written here as the
 * account goes. Rows of users deleted before this stay unattributed: the name
 * is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->string('user_name')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->dropColumn('user_name');
        });
    }
};
