<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // Single central-management token. Only one row exists in the settings
            // table (singleton pattern), so this is effectively unique without a
            // compound index. nullable so the column exists before the feature
            // is first enabled.
            $table->string('central_token', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropIndex(['central_token']);
            $table->dropColumn('central_token');
        });
    }
};
