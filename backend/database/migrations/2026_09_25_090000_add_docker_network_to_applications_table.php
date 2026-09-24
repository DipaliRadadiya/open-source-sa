<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The network a container site joins.
 *
 * A column rather than a settings key, for the same reason `image` and
 * `container_port` are columns: the compose file is rendered from it on every
 * deploy, and the delete guard on the Docker page has to query it. A value
 * buried in a JSON blob can be neither.
 *
 * Nullable, and null means "Docker's default bridge" — which is what every
 * existing container site is on, so this migration changes no behaviour until
 * somebody picks a network.
 *
 * Not a foreign key: Docker owns these objects, not the panel. A network can
 * be removed with `docker network rm` by someone on the box, and a dangling
 * name here has to be reportable rather than impossible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('docker_network')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('docker_network');
        });
    }
};
