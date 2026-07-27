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
            $table->boolean('ssh_access')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_users');
    }
};
