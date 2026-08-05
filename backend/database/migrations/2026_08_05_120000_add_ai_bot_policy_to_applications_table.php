<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            // allow_all | block_training | block_all — see App\Enums\AiBotPolicy.
            // Default is what every site does today: nothing blocked.
            $table->string('ai_bot_policy')->default('allow_all')->after('basic_auth_password');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('ai_bot_policy');
        });
    }
};
