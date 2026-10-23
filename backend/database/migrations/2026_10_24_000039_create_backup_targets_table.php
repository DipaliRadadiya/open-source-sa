<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The backup schedule of a site or of a database on its own.
 *
 * A target belongs to a site **or** a database — exactly one, enforced where
 * the rows are written — so both foreign keys are nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('database_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('storage_destination_id')->constrained()->restrictOnDelete();
            $table->string('type', 16);
            $table->unsignedSmallInteger('retention_count');
            $table->json('file_excludes')->nullable();
            $table->json('database_excludes')->nullable();

            // Which of a container site's volumes the backup captures.
            //
            // Nullable, and null means every volume — including ones added
            // later. That is why it is nullable rather than defaulting to the
            // volumes a site has today: under null a site that gains a volume
            // next month stays covered, where a list captured at setup silently
            // stops covering it and nothing anywhere reports the gap. An
            // explicit list is for someone who has deliberately excluded
            // something.
            $table->json('volume_scope')->nullable();
            $table->boolean('enabled')->default(true);

            // A backup nobody remembers to click is not a backup. The schedule
            // lives here rather than in a cron file so it can never drift with
            // the user-managed Cronjobs feature — the same reasoning, and the
            // same frequency vocabulary, as disk_cleaner_schedules.
            $table->string('frequency', 16)->default('daily');
            // "HH:MM" — e.g. "14:30". Null means use the server default (02:00).
            $table->string('schedule_time', 5)->nullable();
            $table->timestamp('last_run_at')->nullable();

            $table->timestamps();

            // One target per application. "Daily files, weekly database" is not
            // expressible, deliberately: two schedules per app is a second
            // concept to explain, and nobody has asked for it yet.
            $table->unique('application_id');

            // Likewise one target per database.
            $table->unique('database_id');

            // The scheduler asks "which targets are enabled" every minute.
            $table->index(['enabled', 'last_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_targets');
    }
};
