<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an adopted job was found on the server.
 *
 * Server Sync imports jobs out of /etc/cron.d, but the slug it gives them names
 * the file the panel *would* write — not the one it read. Without the original
 * path, the first edit wrote a second file and left the first one running, and
 * a delete removed only ours. Both are answers the panel cannot give correctly
 * from the slug alone.
 *
 * Null for a job created in the panel: there is no earlier file to reconcile.
 *
 * Added as its own migration rather than folded into the create — the rule that
 * says fold assumes `migrate:fresh` is available, and the shared development
 * database holds work that cannot be recreated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cronjobs', function (Blueprint $table) {
            $table->string('source_path')->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('cronjobs', function (Blueprint $table) {
            $table->dropColumn('source_path');
        });
    }
};
