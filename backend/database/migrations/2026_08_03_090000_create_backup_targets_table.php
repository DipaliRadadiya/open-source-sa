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

            // A backup nobody remembers to click is not a backup. The schedule
            // lives here rather than in a cron file so it can never drift with
            // the user-managed Cronjobs feature — the same reasoning, and the
            // same frequency vocabulary, as disk_cleaner_schedules.
            $table->string('frequency', 16)->default('daily');
            $table->timestamp('last_run_at')->nullable();

            $table->timestamps();

            // One target per application. "Daily files, weekly database" is not
            // expressible, deliberately: two schedules per app is a second
            // concept to explain, and nobody has asked for it yet.
            $table->unique('application_id');

            // The scheduler asks "which targets are enabled" every minute.
            $table->index(['enabled', 'last_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_targets');
    }
};
