<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            // Taken offline without being deleted — a vhost swap to a small
            // "unavailable" page, reversible. Null means serving normally,
            // which is what every site does today.
            $table->timestamp('disabled_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('disabled_at');
        });
    }
};
