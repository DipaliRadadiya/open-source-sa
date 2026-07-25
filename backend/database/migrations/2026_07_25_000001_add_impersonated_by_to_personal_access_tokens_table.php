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
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            // The admin user id when this token was issued via impersonation.
            // Plain nullable indexed column (no FK) — the token is short-lived
            // (1h) and admin deletion is already guarded elsewhere, so a hard
            // FK constraint isn't worth the SQLite ALTER-table rebuild cost.
            $table->unsignedBigInteger('impersonated_by')->nullable()->after('tokenable_id')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['impersonated_by']);
            $table->dropColumn('impersonated_by');
        });
    }
};
