<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per one-click update of a site's own software (Moodle,
        // Mautic, ...). The history is what tells a user what changed, and
        // the running row is what stops a second update starting over it.
        Schema::create('application_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_version', 64)->nullable();
            $table->string('to_version', 64)->nullable();
            $table->string('status', 16)->index();
            $table->string('reason', 64)->nullable();
            $table->string('step', 64)->nullable();
            $table->string('reference', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['application_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_updates');
    }
};
