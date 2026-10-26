<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `expires_at`: when a user's export deletes itself; null = kept until the
     * user deletes it, which is what every existing export keeps doing.
     *
     * `safety_copy`: whether an import takes a copy of the database before
     * replacing it. True on every existing row — they all did.
     */
    public function up(): void
    {
        Schema::table('database_exports', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('finished_at');
        });

        Schema::table('database_imports', function (Blueprint $table) {
            $table->boolean('safety_copy')->default(true)->after('safety_export_id');
        });
    }

    public function down(): void
    {
        Schema::table('database_imports', function (Blueprint $table) {
            $table->dropColumn('safety_copy');
        });

        Schema::table('database_exports', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
