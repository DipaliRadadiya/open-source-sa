<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->boolean('waf_enabled')->default(false)->after('ai_bot_policy');
            // detect | enforce — see App\Enums\WafMode.
            $table->string('waf_mode')->default('detect')->after('waf_enabled');
            // Which of the six rule categories are active. Null means "all of
            // them" — every site that has never touched this screen gets full
            // coverage the moment it's turned on, not an empty set.
            $table->json('waf_categories')->nullable()->after('waf_mode');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['waf_enabled', 'waf_mode', 'waf_categories']);
        });
    }
};
