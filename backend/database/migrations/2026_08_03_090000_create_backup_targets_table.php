<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('storage_destination_id')->constrained()->restrictOnDelete();
            $table->string('type', 16);
            $table->unsignedSmallInteger('retention_count');
            $table->json('file_excludes')->nullable();
            $table->json('database_excludes')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique('application_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_targets');
    }
};
