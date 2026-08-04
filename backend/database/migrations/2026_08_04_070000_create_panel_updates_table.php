<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel_updates', function (Blueprint $table): void {
            $table->id();

            // Who pressed the button. Nullable so deleting a panel user does
            // not delete the record that the update happened.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status')->default('pending');

            // Which step the runner is on. The runner writes this to a state
            // file outside the repository and the panel copies it here — the
            // update restarts php-fpm and the queue, so no PHP process can be
            // trusted to still be alive to record its own completion.
            $table->string('current_step')->nullable();

            $table->string('from_version')->nullable();
            $table->string('from_commit')->nullable();
            $table->string('to_version')->nullable();
            $table->string('to_commit')->nullable();

            // Classified failure code (never raw stderr) plus a reference the
            // user can quote; the detail lives in the log.
            $table->string('reason')->nullable();
            $table->string('reference')->nullable();

            // Whether the runner put the old release back after a failure.
            $table->boolean('rolled_back')->default(false);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_updates');
    }
};
