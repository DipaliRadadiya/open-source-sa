<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a containerised application is, beyond a domain and a port.
 *
 * All nullable: every existing application is a PHP, Node or static site and
 * has none of these. A container has all three.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            // The image reference as the user gave it, tag or digest included.
            // Stored verbatim rather than split into name/tag: a reference can
            // carry a registry host, a port, a namespace, a tag AND a digest,
            // and a schema that takes it apart has to put it back together
            // identically or it is pulling something else.
            $table->string('image')->nullable();

            // The port INSIDE the container. Distinct from `app_port`, which
            // is the loopback port on the host that the panel allocated and
            // nginx proxies to. Conflating them is how you get a container
            // published on a port another application already holds.
            $table->unsignedInteger('container_port')->nullable();

            // A ceiling, so one container cannot take the box down and the
            // panel with it. Nullable here and defaulted at render time, so
            // the default can change without a migration rewriting rows.
            $table->string('memory_limit')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['image', 'container_port', 'memory_limit']);
        });
    }
};
