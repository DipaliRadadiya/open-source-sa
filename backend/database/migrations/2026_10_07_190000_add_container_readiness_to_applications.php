<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the panel last saw of a container site, and why it last failed (DS-03).
 *
 * `compose up` exits 0 for a container that dies a second later and for one
 * listening on a port nginx is not proxying to, so a deploy now ends with a
 * readiness check. Its outcome is stored here rather than only in the ops log:
 *
 *  - `container_status`: `running`, `restarting`, `exited` or `not_answering`,
 *    as of the last deploy. Null for a site no check has run on.
 *  - `last_failure`: `{reason, params, log, at}` — a reason code titled at read
 *    time in the viewer's locale, the values its sentence needs, and the last
 *    lines of the container's own log. Cleared by the next deploy that passes.
 *
 * Nullable, no backfill: existing sites have never been checked, and null says
 * exactly that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('container_status', 32)->nullable();
            $table->json('last_failure')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['container_status', 'last_failure']);
        });
    }
};
