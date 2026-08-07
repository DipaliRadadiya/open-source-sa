<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->string('log_key', 40)->nullable()->after('reference');
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->dropColumn('log_key');
        });
    }
};
