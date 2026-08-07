<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_application_id')->constrained('applications');
            $table->foreignId('target_application_id')->nullable()->constrained('applications');
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('name', 255)->nullable();         // optional clone name
            $table->string('status', 20)->default('pending');
            $table->string('current_step', 40)->nullable();  // nullable = not started yet
            $table->string('reason', 40)->nullable();         // stable error key
            $table->string('reference', 40)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['source_application_id', 'status']);
            $table->index(['target_application_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clones');
    }
};
