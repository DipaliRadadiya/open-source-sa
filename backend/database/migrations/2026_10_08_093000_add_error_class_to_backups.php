<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a failed backup could not reach its storage (FS-C33).
 *
 * `reason` is the step — `upload_artifact` — which is where, not why. The
 * cause (wrong keys, no permission, a bucket that is gone) was only in the
 * server log. Same categories the destination test already reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->string('error_class', 64)->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->dropColumn('error_class');
        });
    }
};
