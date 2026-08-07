<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cronjobs', function (Blueprint $table) {
            $table->foreignId('application_id')
                ->nullable()
                ->after('system_user_id')
                ->index()
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cronjobs', function (Blueprint $table) {
            $table->dropForeign(['application_id']);
            $table->dropIndex(['application_id']);
            $table->dropColumn('application_id');
        });
    }
};
