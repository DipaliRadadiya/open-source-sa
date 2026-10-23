<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The release a one-click application currently resolves to, and the Node
 * versions that release runs on.
 *
 * Exists because two facts that must agree were maintained by hand in
 * different files, and nothing noticed when they stopped agreeing. The n8n
 * installer resolves `latest` at install time, while `N8nSiteType` declared
 * its Node range as literal numbers someone had transcribed from the
 * registry months earlier. n8n 1.x declared `>=20.19 <= 24.x`; 2.x declares
 * `>=24.0.0` and no ceiling at all. A moving version pin beside a static
 * range drifts silently, and the failure it produces is a site that installs
 * without complaint and then refuses to start.
 *
 * So the range is read from the package rather than remembered: whatever
 * `engines.node` the resolved release declares is what the picker offers.
 *
 * A table rather than the cache, for the reason `npm_releases` gives:
 * `php artisan optimize:clear` runs on deploy and would wipe it, and
 * reference data that disappears on deploy is a bug. The registry is read by
 * a scheduled command, never inside a request — a self-hosted panel behind a
 * firewall must not hang on a page load because registry.npmjs.org is
 * unreachable. When a row is missing the site type falls back to the range it
 * declares in code, so an offline server keeps working on the last answer
 * anybody had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_package_releases', function (Blueprint $table): void {
            // The npm package a site type installs: 'n8n'.
            $table->string('package', 100)->primary();
            // What the configured pin resolved to when this was refreshed —
            // '2.39.7' for a pin of `latest`. Kept so an operator can see
            // which release the range describes.
            $table->string('version', 40);
            // That release's `engines.node`, verbatim: '>=24.0.0', or
            // '>=20.19 <= 24.x'. A semver range, not a version.
            $table->string('node_range', 120);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_package_releases');
    }
};
