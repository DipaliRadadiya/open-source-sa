<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Node site's own `location /` block (vhosts the same as v7, 2026-11-08).
 *
 * v7 keeps a user-editable nginx block per MERN app (`mern_deployments.nginx_conf`)
 * and writes it into the vhost. Null means the panel's default proxy block; the
 * importer fills it only when a v7 site's block differs from v7's default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->text('nginx_location')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('nginx_location');
        });
    }
};
