<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cronjobs', function (Blueprint $table) {
            // A cron job name is a unique, human-facing identifier — validation
            // enforces it at the API, this index guards against races.
            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::table('cronjobs', function (Blueprint $table) {
            $table->dropUnique(['name']);
        });
    }
};
