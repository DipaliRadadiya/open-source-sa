<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - addon_runs.input: what a queued addon command reads on stdin -- a
 *   blueprint with its script, Object Cache Pro's Redis credentials. Stored
 *   encrypted (the model casts it), because it is exactly what must not sit
 *   in the clear anywhere.
 * - application_redis_accounts: the Redis ACL user each site with Object
 *   Cache Pro gets, limited to its own key prefix. The password is encrypted;
 *   the panel needs it back to rewrite the site's settings on rotation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addon_runs', function (Blueprint $table) {
            $table->text('input')->nullable();
        });

        Schema::create('application_redis_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('username', 64)->unique();
            $table->text('password');
            $table->string('prefix', 64);
            $table->text('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_redis_accounts');

        Schema::table('addon_runs', function (Blueprint $table) {
            $table->dropColumn('input');
        });
    }
};
