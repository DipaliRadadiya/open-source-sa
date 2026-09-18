<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where this server's OpenLiteSpeed vhosts actually live.
 *
 * A fresh install puts them under the path in config. A server migrated from
 * the old panel does not: that panel wrote them to `/etc/<brand>-ols/`, where
 * `<brand>` is its own build-time name — `strings.ToLower(ServiceName)` in the
 * agent, and `manageWhitelabel->folder_name` in its backend. On a white-label
 * reseller's box it is that reseller's brand, and no list of names shipped
 * here is right on everybody's server. It is the same trap that made the old
 * agent's unit `sureshcloud.service` rather than the name the source suggested.
 *
 * So it is detected from the box and recorded, rather than configured. A
 * column rather than a config value because it is a fact *about this server*,
 * which is what this table is for, and because the answer must survive
 * `optimize:clear` on the next deploy.
 *
 * Null means nothing was detected and the configured default stands — the
 * normal state of a server that never ran the old panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_capabilities', function (Blueprint $table): void {
            $table->string('ols_vhost_root')->nullable()->after('web_server');
        });
    }

    public function down(): void
    {
        Schema::table('server_capabilities', function (Blueprint $table): void {
            $table->dropColumn('ols_vhost_root');
        });
    }
};
