<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extra directories a site may reach when open_basedir is on.
 *
 * An additive migration rather than a change to the create migration, which
 * is what the one-migration-per-table convention would otherwise ask for.
 * That convention was written while nothing anywhere held real data; servers
 * are running the panel now, and folding this into the create migration would
 * mean an existing install only gets the column by wiping its database.
 *
 * The base paths stay computed rather than stored — app root, the site's own
 * session directory and /tmp. Those are what the site cannot run without, so
 * they are not the user's to remove; this column only ever adds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_php_settings', function (Blueprint $table) {
            $table->text('open_basedir_paths')->nullable()->after('open_basedir_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('application_php_settings', function (Blueprint $table) {
            $table->dropColumn('open_basedir_paths');
        });
    }
};
