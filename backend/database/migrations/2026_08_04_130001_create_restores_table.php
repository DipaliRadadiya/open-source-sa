<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One attempt to put an application back to a backup.
        //
        // The row is written before the work starts and updated step by step,
        // because this is the one operation in the panel that destroys data:
        // if the worker dies mid-run, the row must still say how far it got
        // and where the previous state was left.
        Schema::create('restores', function (Blueprint $table) {
            $table->id();

            // Nullable: retention may remove the backup later, and losing the
            // history of a restore because its source was pruned would hide
            // exactly the event someone is trying to reconstruct.
            $table->foreignId('backup_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('type');                        // filesystem | database | full
            $table->string('status')->index();             // pending | running | succeeded | failed
            $table->string('current_step')->nullable();
            $table->string('reason')->nullable();          // the step that failed, never raw stderr
            $table->uuid('reference')->nullable();

            // The backup taken of the current state before anything was
            // overwritten — the way back.
            $table->foreignId('safety_backup_id')->nullable()->constrained('backups')->nullOnDelete();

            // Where the previous site directory was moved to. Kept after a
            // successful restore too: "the restore worked but the site is
            // wrong" is still recoverable while this exists.
            $table->string('rollback_path')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restores');
    }
};
