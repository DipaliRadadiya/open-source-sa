<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disk_cleaner_runs', function (Blueprint $table) {
            $table->id();
            $table->string('trigger'); // manual | scheduled
            $table->json('categories');
            // Per-category freed bytes { key: bytes }.
            $table->json('freed')->nullable();
            $table->unsignedBigInteger('freed_total')->default(0);
            $table->string('status')->default('completed'); // completed | failed
            $table->unsignedTinyInteger('disk_percent')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disk_cleaner_runs');
    }
};
