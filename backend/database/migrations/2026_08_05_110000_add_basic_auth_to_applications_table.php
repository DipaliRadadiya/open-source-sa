<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            // Whole-site HTTP Basic Auth — a login prompt in front of the
            // site, not a panel-level permission. Off (false) is what every
            // site does today.
            $table->boolean('basic_auth_enabled')->default(false)->after('disabled_at');
            $table->string('basic_auth_username')->nullable()->after('basic_auth_enabled');
            // A bcrypt hash, written straight into the site's .htpasswd file.
            // Never the plaintext password, and never decrypted back to it —
            // this only ever needs to be verified, not shown to anyone again.
            $table->string('basic_auth_password')->nullable()->after('basic_auth_username');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['basic_auth_enabled', 'basic_auth_username', 'basic_auth_password']);
        });
    }
};
