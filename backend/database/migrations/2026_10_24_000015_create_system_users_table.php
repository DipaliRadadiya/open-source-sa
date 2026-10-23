<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Linux OS accounts that own/run sites. `password` is stored plaintext
        // (operator decision) for SFTP/login; nullable when unset.
        Schema::create('system_users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('home_path');
            $table->string('shell')->default('/bin/bash');
            $table->boolean('sudo')->default(false);
            $table->string('password')->nullable();
            // false, matching CreateSystemUser's own `?? false`: the action
            // always writes this column explicitly, so the old `true` default
            // was never reached and documented the opposite of what the panel
            // does. A schema that claims SSH is on by default is the wrong
            // thing to read when auditing who can reach the box.
            $table->boolean('ssh_access')->default(false);
            // Container login (RC-28): this user is in the sv-app-shell group,
            // so its SSH login opens its Docker app's container rather than a
            // shell on the server. Recorded beside `ssh_access` for the same
            // reason that one is: the group membership is the server's fact,
            // this is the panel's record of what it set.
            $table->boolean('container_login')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_users');
    }
};
