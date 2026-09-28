<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The volumes a container site mounts, and where.
 *
 * JSON rather than a table, and a list rather than a single value — which is the
 * whole difference from `docker_network`. A site joins ONE network; it mounts as
 * many volumes as it has directories worth keeping, and each one carries a path
 * INSIDE the container. `sv-app-7_ghost-db` is only useful at `/var/lib/mysql`;
 * the same volume at `/app/uploads` is a different thing entirely, so the name
 * alone is not enough information to act on.
 *
 * Shape: `[{"volume": "name", "path": "/var/lib/mysql"}, …]`. Validated on the
 * way in — see UpdateContainerRequest — because this is rendered into a compose
 * file and a path that collides with the site's own bind mount silently shadows
 * the site's files.
 *
 * Nullable, and empty means what every existing container site has today: the
 * document-root bind mount and nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->json('volume_mounts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('volume_mounts');
        });
    }
};
