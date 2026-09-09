<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The newest npm release of each npm major, and the Node versions it runs on.
 *
 * Exists because "the latest npm" is not one number. npm 12 requires Node
 * `^22.22.2 || ^24.15.0 || >=26.0.0`, so on a box running Node 20 the latest
 * npm is 11.x and offering 12 would be offering something that cannot start.
 * Storing the Node requirement alongside the version is what lets the panel
 * answer per Node version instead of per registry.
 *
 * A table rather than the cache, for the reason the runtime_lifecycles
 * migration gives: `php artisan optimize:clear` runs on deploy and would wipe
 * it, and reference data that disappears on deploy is a bug. The registry is
 * read by a scheduled command, never inside a request — a self-hosted panel
 * behind a firewall must not hang on a page load because registry.npmjs.org
 * is unreachable.
 *
 * One row per major rather than all 600-odd releases: the resolver only ever
 * wants the newest of each line, and twelve rows makes the lookup a handful
 * of range checks instead of a scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('npm_releases', function (Blueprint $table): void {
            $table->id();
            // npm's major line: '10', '11', '12'.
            $table->string('major', 10)->unique();
            // The newest release on that line: '11.19.1'.
            $table->string('version', 40);
            // That release's `engines.node`, verbatim from the registry —
            // an npm semver range, not a single version.
            $table->string('node_range', 120);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('npm_releases');
    }
};
