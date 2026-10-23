<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Redis ACL user each site with Object Cache Pro gets, limited to its own
 * key prefix. The password is encrypted; the panel needs it back to rewrite the
 * site's settings on rotation.
 */
return new class extends Migration
{
    public function up(): void
    {
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
    }
};
