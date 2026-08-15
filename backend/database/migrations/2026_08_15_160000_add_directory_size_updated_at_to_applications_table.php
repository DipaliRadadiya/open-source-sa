<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the stored directory size was measured.
 *
 * The size alone cannot be read honestly: a number with no date reads as
 * current, and this one is only recomputed when somebody asks. Showing "42 GB,
 * measured 3 hours ago" is a different statement from "42 GB", and only one of
 * them is true.
 *
 * Null means never measured, which is what the size column already means when
 * it is null — the two move together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->timestamp('directory_size_updated_at')->nullable()->after('directory_size_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('directory_size_updated_at');
        });
    }
};
