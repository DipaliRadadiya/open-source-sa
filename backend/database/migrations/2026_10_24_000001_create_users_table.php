<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Panel login accounts. Username + password only (no email / password
        // reset). `is_admin` gates the admin area; feature access is role-based
        // via the `role_user` pivot.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->boolean('is_admin')->default(false);
            // Machine accounts. A system user owns API tokens but is not a
            // person: it never logs in, is not listed or editable in the admin
            // area, and is not counted in the dashboard's user totals.
            $table->boolean('is_system')->default(false);
            $table->string('name');
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
            // When and from where the user last signed in (OLD-17), so the
            // Users screen can tell an account in daily use from one nobody
            // has opened in a year. Null until the first sign-in.
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
