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
            $table->foreignId('source_application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('target_application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 255)->nullable();         // optional clone name
            // The domain the clone will be served on. Required, and captured
            // here rather than only on the target application because the
            // record exists before the target does — the queued job needs to
            // know where it is cloning to before it has anything to clone into.
            $table->string('domain', 255);
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
