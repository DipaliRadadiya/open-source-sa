<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A backup taken automatically just before a restore overwrites the
        // application. It is the only way back from a restore that turns out
        // to be wrong, so it must never be deleted to make room for a
        // scheduled one — retention skips it entirely.
        Schema::table('backups', function (Blueprint $table) {
            $table->boolean('is_safety')->default(false)->after('type')->index();
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn('is_safety');
        });
    }
};
