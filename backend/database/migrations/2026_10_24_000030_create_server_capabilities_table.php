<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What this server is and what it can run. One row — this panel manages a
     * single server.
     *
     * Written by the installation script, so the request path is a plain
     * database read rather than a probe. If the row is missing (a server
     * migrated in from another panel, which never ran our installer) the first
     * feature that needs it detects once and saves the result.
     *
     * `stack` is how the box was BUILT; `capabilities` is what it can run NOW.
     * They legitimately diverge — installing Node on a LEMP box adds the
     * capability without changing the fact that it was built as LEMP — so the
     * UI filters on capabilities, never on the stack.
     */
    public function up(): void
    {
        Schema::create('server_capabilities', function (Blueprint $table) {
            $table->id();
            // lemp | lamp | ols | mern — the installer's preset, a label.
            $table->string('stack')->nullable();
            // nginx | apache | openlitespeed — who owns :80, decides the vhost
            // syntax. `mern` is not a value here: MERN uses nginx.
            $table->string('web_server')->nullable();
            // Where this server's OpenLiteSpeed vhosts actually live.
            //
            // A fresh install puts them under the path in config. A server migrated
            // from the old panel does not: that panel wrote them to
            // `/etc/<brand>-ols/`, where `<brand>` is its own build-time name —
            // `strings.ToLower(ServiceName)` in the agent, and
            // `manageWhitelabel->folder_name` in its backend. On a white-label
            // reseller's box it is that reseller's brand, and no list of names
            // shipped here is right on everybody's server. It is the same trap that
            // made the old agent's unit `sureshcloud.service` rather than the name
            // the source suggested.
            //
            // So it is detected from the box and recorded, rather than configured.
            // A column rather than a config value because it is a fact *about this
            // server*, which is what this table is for, and because the answer must
            // survive `optimize:clear` on the next deploy.
            //
            // Null means nothing was detected and the configured default stands —
            // the normal state of a server that never ran the old panel.
            $table->string('ols_vhost_root')->nullable();
            // {"php": true, "node": false, ...} — what can actually run.
            $table->json('capabilities')->nullable();
            // installer | detected — where the row came from.
            $table->string('source')->default('detected');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_capabilities');
    }
};
