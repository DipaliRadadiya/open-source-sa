<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which git service a site's code came from.
 *
 * `site_type` is `git` for all three — one GitSiteType covers GitHub, GitLab
 * and Bitbucket, because the deploy recipe is identical and only the account's
 * API differs. So nothing on the application said where the code came from, and
 * a client wanting to show the provider had to fetch every git account and join
 * by hand.
 *
 * Nullable, and deliberately not backfilled (operator's call, 2026-09-15):
 * applications created from now on carry it, older rows stay null. Null is also
 * the honest answer for a public URL on a self-hosted host — `git.example.com`
 * names no provider, and guessing one would be worse than admitting it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            // Indexed because the point of a column over a derived value is
            // being able to ask the database "which sites are on GitHub".
            $table->string('git_provider')->nullable()->index()->after('git_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropIndex(['git_provider']);
            $table->dropColumn('git_provider');
        });
    }
};
