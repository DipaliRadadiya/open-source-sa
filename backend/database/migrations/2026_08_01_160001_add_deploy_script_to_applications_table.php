<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The commands a deploy runs after the code is checked out.
 *
 * `build_command` was one line, which covers `npm run build` and nothing else.
 * A real deployment needs a sequence — install dependencies, build assets, run
 * migrations, restart queue workers — and needs it to be the user's to edit,
 * because only they know what their application requires.
 *
 * Added rather than folded into the create migration: the applications table
 * holds live rows on running installations, so this has to be additive. The
 * same reason `add_disk_io_to_server_metrics_table` exists.
 *
 * `build_command` is left in place and becomes the fallback when no script is
 * set, so an application configured before this keeps deploying exactly as it
 * did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->text('deploy_script')->nullable()->after('build_command');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('deploy_script');
        });
    }
};
