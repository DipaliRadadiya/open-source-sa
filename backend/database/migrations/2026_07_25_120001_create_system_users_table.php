<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('home_path');
            $table->string('shell')->default('/bin/bash');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_users');
    }
};
