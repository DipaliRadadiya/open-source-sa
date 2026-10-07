<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a simple-mode container sees the site's own directory.
 *
 * Until now that was always `/app`, written into the compose template. `/app`
 * is also where a large share of images keep their program — changedetection.io,
 * Gotify, Actual, Umami and every linuxserver.io image among them — and an empty
 * `public_html` bind-mounted over it hides the program, so the container
 * crash-loops and the site shows "Setup failed".
 *
 * Per application rather than a new constant, because the path is part of a
 * compose file that is already on disk for every existing site. **Null means
 * `/app`**, which is what those sites were created with: a redeploy renders the
 * file byte-for-byte as before, and a site that works keeps working. New sites
 * are created with the new path — see `ContainerMountPath::SITE_MOUNT`.
 *
 * No backfill. Existing sites are left exactly as they are (standing rule:
 * new sites only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('site_mount_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('site_mount_path');
        });
    }
};
