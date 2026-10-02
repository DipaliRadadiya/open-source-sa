<?php

use App\Http\Controllers\API\Server\Addons\AddonController;
use App\Http\Controllers\API\Server\Addons\InsightsAddonController;
use App\Http\Controllers\API\Server\Addons\WordPressAddonController;
use App\Services\Addons\WpToolkitCommands;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Paid addons, for the central panel only
|--------------------------------------------------------------------------
|
| The OSS panel does not offer these features itself. Central calls them with
| this server's central token; everyone else — administrators included — gets
| a 404 (`central.only`). The addon binaries check their own licence; a server
| that has not bought one gets 403 `addon_licence_required` from every route.
|
| Synchronous routes answer with the addon's own JSON, unchanged. Routes that
| can outlast a request answer 202 with a run to poll at /central/addons/runs/{run}.
|
*/

Route::middleware('central.only')->prefix('central/addons')->group(function (): void {
    Route::get('/', [AddonController::class, 'index']);
    Route::get('/runs/{run}', [AddonController::class, 'run']);

    // ── WP Toolkit ───────────────────────────────────────────────────────────
    Route::prefix('applications/{application}/wordpress')->group(function (): void {
        $wp = fn (string $method, string $uri, string $command) => Route::{$method}($uri, WordPressAddonController::class)
            ->defaults('command', $command)
            ->where('slug', WpToolkitCommands::SLUG);

        $wp('get', 'plugins', 'plugins.list');
        $wp('post', 'plugins', 'plugins.install');
        $wp('post', 'plugins/update-all', 'plugins.update-all');
        $wp('delete', 'plugins/{slug}', 'plugins.uninstall');
        $wp('post', 'plugins/{slug}/toggle', 'plugins.toggle');
        $wp('post', 'plugins/{slug}/update', 'plugins.update');

        $wp('get', 'themes', 'themes.list');
        $wp('post', 'themes', 'themes.install');
        $wp('post', 'themes/update-all', 'themes.update-all');
        $wp('delete', 'themes/{slug}', 'themes.uninstall');
        $wp('post', 'themes/{slug}/activate', 'themes.activate');
        $wp('post', 'themes/{slug}/update', 'themes.update');

        $wp('get', 'core/version', 'core.version');
        $wp('post', 'core/update', 'core.update');
        $wp('post', 'core/update-db', 'core.update-db');
        $wp('post', 'core/verify-checksums', 'core.verify-checksums');

        $wp('get', 'summary/{part}', 'summary')->whereIn('part', ['site', 'users', 'themes', 'plugins', 'cron']);

        $wp('post', 'search-replace', 'search-replace');
        $wp('post', 'rewrite/flush', 'rewrite.flush');
        $wp('post', 'cache/flush', 'cache.flush');
        $wp('post', 'cron/run', 'cron.run');
        $wp('put', 'cron', 'cron.set');

        $wp('get', 'debug', 'debug.get');
        $wp('put', 'debug', 'debug.set');
        $wp('get', 'settings', 'settings.get');
        $wp('put', 'settings', 'settings.set');
        $wp('get', 'maintenance-mode', 'maintenance.get');
        $wp('put', 'maintenance-mode', 'maintenance.set');

        $wp('get', 'security', 'security.get');
        $wp('put', 'security/{rule}', 'security.set')->whereIn('rule', ['xmlrpc', 'uploads-php']);
    });

    // ── InsightHub ───────────────────────────────────────────────────────────
    Route::get('insights/applications', [InsightsAddonController::class, 'applications']);
    Route::post('applications/{application}/insights/register', [InsightsAddonController::class, 'register']);
    Route::delete('applications/{application}/insights/register', [InsightsAddonController::class, 'unregister']);
    Route::get('applications/{application}/insights/{group}/{report}', [InsightsAddonController::class, 'report'])
        ->whereIn('group', array_keys(InsightsAddonController::REPORTS));
});
