<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('permissions')->nullOnDelete();
            $table->string('level');
            $table->string('sub_level');
            $table->string('name');
            $table->string('title');
            $table->string('icon')->nullable();
            $table->string('url')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            // name is unique per level, not globally — the same name (e.g.
            // "logs", "settings") can exist under different levels (server
            // vs. application) as distinct permission entries.
            $table->unique(['name', 'level']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
