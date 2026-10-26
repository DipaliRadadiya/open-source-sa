<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The one keep choice a clean runs with (0 = delete all, N = keep N days).
     * Null on every existing row on purpose: it means "from before the choice",
     * and each category answers it with what it always did.
     */
    public function up(): void
    {
        Schema::table('disk_cleaner_schedules', function (Blueprint $table) {
            $table->unsignedTinyInteger('keep_days')->nullable()->after('threshold_percent');
        });

        Schema::table('disk_cleaner_runs', function (Blueprint $table) {
            $table->unsignedTinyInteger('keep_days')->nullable()->after('categories');
        });
    }

    public function down(): void
    {
        Schema::table('disk_cleaner_runs', function (Blueprint $table) {
            $table->dropColumn('keep_days');
        });

        Schema::table('disk_cleaner_schedules', function (Blueprint $table) {
            $table->dropColumn('keep_days');
        });
    }
};
