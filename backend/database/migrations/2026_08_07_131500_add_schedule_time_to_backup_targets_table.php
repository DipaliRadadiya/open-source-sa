<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_targets', function (Blueprint $table): void {
            $table->string('schedule_time', 5)->nullable()->after('frequency');
            // 5 chars: "HH:MM" — e.g. "14:30". null means use the server default (02:00).
        });
    }

    public function down(): void
    {
        Schema::table('backup_targets', function (Blueprint $table): void {
            $table->dropColumn('schedule_time');
        });
    }
};
