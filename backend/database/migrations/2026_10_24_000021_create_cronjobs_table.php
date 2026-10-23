<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cronjobs', function (Blueprint $table) {
            $table->id();
            // Unique, human-facing identifier (validation enforces at the API;
            // the index guards races).
            $table->string('name')->unique();
            // Stable slug used for the /etc/cron.d filename (survives re-import,
            // unlike the auto-increment id). Always set by the app on create;
            // nullable at the DB level only.
            $table->string('slug')->nullable()->unique();
            // Original server path for an adopted job; null for panel-created jobs.
            $table->string('source_path')->nullable();
            // The exact crontab line an adopted job was read from.
            //
            // A job Server Sync finds in a user's crontab lives inside a file the
            // panel does not own, so there is no path to remove when the panel
            // takes it over. Without the line, the first edit wrote the panel's
            // own copy and left the crontab entry running: the job ran twice
            // (found on a real server, 2026-09-24). `source_path` says where
            // (`crontab:<user>`), this says what.
            //
            // Nullable and additive: every job created by the panel, and every job
            // adopted from /etc/cron.d, has no line and needs none.
            $table->text('source_line')->nullable();
            // The OS user the job runs as — canonical, always set. May be a
            // panel System User or a default/unmanaged account (root, www-data).
            $table->string('username')->index();
            // Soft-link to a panel System User when the target is one; null for
            // default/unmanaged users. Cascade so deleting the user cleans its jobs.
            $table->foreignId('system_user_id')->nullable()->constrained('system_users')->cascadeOnDelete();
            // The application this job belongs to, when it was created from an
            // application's own Cronjobs screen. Null for server-level jobs.
            // `nullOnDelete` rather than cascade: the job is a real /etc/cron.d
            // file, and removing a site should not silently stop a command the
            // user may still want running.
            $table->foreignId('application_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            // Which cron jobs the panel created for a site itself.
            //
            // Deleting a site keeps its cron jobs on purpose — a job the user added
            // may be something they still want running. The background job a
            // Nextcloud or Moodle install adds is not that: it runs the site's own
            // `cron.php`, and once the site is gone it fails every tick and holds
            // the site user busy. This records which of the two a row is.
            $table->boolean('application_owned')->default(false);
            $table->string('command', 1000);
            $table->string('expression');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cronjobs');
    }
};
