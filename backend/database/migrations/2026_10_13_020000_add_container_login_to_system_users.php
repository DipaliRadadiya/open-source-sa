<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Container login (RC-28): this user is in the sv-app-shell group, so
        // its SSH login opens its Docker app's container rather than a shell on
        // the server. Recorded beside `ssh_access` for the same reason that one
        // is: the group membership is the server's fact, this is the panel's
        // record of what it set.
        Schema::table('system_users', function (Blueprint $table) {
            $table->boolean('container_login')->default(false)->after('ssh_access');
        });
    }

    public function down(): void
    {
        Schema::table('system_users', function (Blueprint $table) {
            $table->dropColumn('container_login');
        });
    }
};
