<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a worker picked an install up (FS-B1).
 *
 * One worker runs installs and site setups in turn, so an install can wait
 * minutes for its turn — and read as "installing" the whole time. Null means
 * still queued. Rows already there are taken as started: they were written
 * before anything could tell the two apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runtime_installs', function (Blueprint $table): void {
            $table->timestamp('picked_up_at')->nullable()->after('started_at');
        });

        DB::table('runtime_installs')->update(['picked_up_at' => DB::raw('started_at')]);
    }

    public function down(): void
    {
        Schema::table('runtime_installs', function (Blueprint $table): void {
            $table->dropColumn('picked_up_at');
        });
    }
};
