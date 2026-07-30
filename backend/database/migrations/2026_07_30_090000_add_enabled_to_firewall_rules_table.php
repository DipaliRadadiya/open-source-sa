<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lets a rule be switched off without being thrown away.
     *
     * Testing whether a rule matters currently means deleting it and hoping it
     * gets recreated correctly — which, for a `deny` rule, means the thing it
     * was blocking is allowed in the meantime.
     */
    public function up(): void
    {
        Schema::table('firewall_rules', function (Blueprint $table) {
            $table->boolean('enabled')->default(true)->after('origin');
        });
    }

    public function down(): void
    {
        Schema::table('firewall_rules', function (Blueprint $table) {
            $table->dropColumn('enabled');
        });
    }
};
