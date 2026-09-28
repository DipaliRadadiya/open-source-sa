<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The secrets a one-click Docker app was installed with.
 *
 * Stored rather than recovered from the rendered compose file, and that is the
 * whole point of the column. Recovering them by scanning for the key name looks
 * fine and does not work: Ghost's template writes `GHOST_DB_PASSWORD` into a key
 * called `database__connection__password`, so a scan finds nothing, decides the
 * secret is absent, and generates a NEW one on the next re-render — rotating the
 * application's password in its own half of the file and not in MySQL's. The
 * site then comes back up unable to reach its own database, with nothing in the
 * panel pointing at the cause.
 *
 * `encrypted:array` at rest. These are live database credentials; the compose
 * file on disk holds them because Compose has to read them, and that file is
 * inside a site directory owned by the site user. The panel's own copy has no
 * such excuse — same reasoning as `webhook_secret`, which is the other value
 * here that grants access to something.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->text('docker_secrets')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('docker_secrets');
        });
    }
};
