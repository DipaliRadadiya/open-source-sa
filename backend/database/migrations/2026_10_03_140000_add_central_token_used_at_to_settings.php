<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When Central last used the panel's key (bug #49).
 *
 * The Central screen said "Connected" the moment a key was generated, before
 * Central had been given it, let alone used it. Null means "waiting for
 * Central"; a new key resets it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->timestamp('central_token_used_at')->nullable()->after('central_token');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropColumn('central_token_used_at');
        });
    }
};
