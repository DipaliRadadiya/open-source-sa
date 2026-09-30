<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The provider's own id for the webhook the panel registered in the
 * repository (a GitHub hook id, a GitLab hook id, a Bitbucket hook uuid).
 *
 * The panel now adds the webhook itself when deploy-on-push is switched on,
 * instead of leaving the user to paste a URL and a secret into the provider.
 * Without the id it could not update the secret on a rotation or remove the
 * hook when deploy-on-push is switched off or the site is deleted.
 *
 * Nullable and additive: null means the panel did not register one — the
 * webhook was set up by hand, or registration was not possible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->string('webhook_remote_id')->nullable()->after('webhook_secret');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropColumn('webhook_remote_id');
        });
    }
};
