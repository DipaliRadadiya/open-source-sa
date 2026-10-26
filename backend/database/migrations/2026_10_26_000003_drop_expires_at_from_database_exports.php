<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exports no longer delete themselves (Dipali, 2026-10-26): the user deletes
     * one when they no longer need it. The column the earlier change added for
     * the date goes with the feature; nothing ever read it but the prune that
     * is gone.
     */
    public function up(): void
    {
        if (Schema::hasColumn('database_exports', 'expires_at')) {
            Schema::table('database_exports', function (Blueprint $table) {
                $table->dropColumn('expires_at');
            });
        }
    }

    public function down(): void
    {
        Schema::table('database_exports', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('finished_at');
        });
    }
};
