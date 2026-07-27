<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permissions are role-based only — direct per-user grants were removed,
     * so the pivot table is dropped. down() recreates it for reversibility.
     */
    public function up(): void
    {
        Schema::dropIfExists('permission_user');
    }

    public function down(): void
    {
        Schema::create('permission_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('view')->default(false);
            $table->boolean('manage')->default(false);
            $table->timestamps();
            $table->unique(['permission_id', 'user_id']);
        });
    }
};
