<?php

use App\Http\Controllers\API\Server\ApplicationContainerController;
use App\Http\Controllers\API\Server\ApplicationController;
use App\Http\Controllers\API\Server\ApplicationDomainController;
use App\Http\Controllers\API\Server\ApplicationNodeVersionController;
use App\Http\Controllers\API\Server\ApplicationRootLockController;
use App\Http\Controllers\API\Server\ApplicationSiteTypeController;
use App\Http\Controllers\API\Server\ApplicationWebhookController;
use App\Http\Controllers\API\Server\ApplicationWebRootController;
use App\Http\Controllers\API\Server\CertificateController;
use App\Http\Controllers\API\Server\DeploymentController;
use App\Http\Controllers\API\Server\MagicLoginController;
use App\Http\Controllers\API\Server\RecipeController;
use App\Http\Controllers\API\Server\ServerCapabilityController;
use App\Http\Controllers\API\Server\SiteTypeController;
use Illuminate\Support\Facades\Route;

// Applications (server panel). Reads gated by `application` (view), mutations
// by `application` (manage).

// What the server is and can run — drives which site types are offered.
Route::get('/server/capabilities', [ServerCapabilityController::class, 'index'])->middleware('permission:application');

Route::get('/site-types', [SiteTypeController::class, 'index'])->middleware('permission:application');

Route::get('/recipes', [RecipeController::class, 'index'])->middleware('permission:application');
Route::get('/recipes/{recipe}', [RecipeController::class, 'show'])
    ->where('recipe', '[a-z][a-z0-9_]{1,39}')
    ->middleware('permission:application');

Route::get('/applications', [ApplicationController::class, 'index'])->middleware('permission:application');
Route::post('/applications', [ApplicationController::class, 'store'])->middleware('permission:application,manage');
Route::get('/applications/port-check', [ApplicationController::class, 'portCheck'])
    ->middleware('permission:application');
// `show` and `sidebar` are polled while provisioning runs, so they drop the global limiter: a
// per-route throttle stacks with `throttle:api`, and a long install would end in a 429.
// `throttle:progress` bounds them instead.
Route::get('/applications/{application}', [ApplicationController::class, 'show'])
    ->withoutMiddleware('throttle:api')
    ->middleware(['permission:application', 'throttle:progress']);
Route::get('/applications/{application}/sidebar', [ApplicationController::class, 'sidebar'])
    ->withoutMiddleware('throttle:api')
    ->middleware(['permission:application', 'throttle:progress']);
Route::put('/applications/{application}', [ApplicationController::class, 'update'])->middleware('permission:application,manage');
Route::post('/applications/{application}/provision', [ApplicationController::class, 'provision'])->middleware('permission:application,manage');
// Gated by the Deployment screen's permission, which also brings the site-type check:
// a WordPress site has no repository, so this 404s there.
Route::post('/applications/{application}/deploy', [ApplicationController::class, 'deploy'])->middleware('permission:app_deployment,manage');
Route::post('/applications/{application}/process/{action}', [ApplicationController::class, 'process'])
    ->middleware('permission:application,manage');

// Own endpoint, not a field on update: it restarts the application (one supervisor must
// release the port before the other can bind it). Throttled like other process actions.
Route::post('/applications/{application}/supervisor/convert', [ApplicationController::class, 'convertSupervisor'])
    ->middleware(['permission:application,manage', 'throttle:10,1']);

// Enable/disable: a Dashboard action, so it stays on the `application` permission.
Route::post('/applications/{application}/disable', [ApplicationController::class, 'disable'])
    ->middleware(['permission:application,manage', 'throttle:10,1']);
Route::post('/applications/{application}/enable', [ApplicationController::class, 'enable'])
    ->middleware(['permission:application,manage', 'throttle:10,1']);

// The site folder's lock against its own user; lets a site adopted by server sync be locked.
Route::get('/applications/{application}/root-lock', [ApplicationRootLockController::class, 'show'])
    ->middleware('permission:application');
Route::post('/applications/{application}/root-lock', [ApplicationRootLockController::class, 'store'])
    ->middleware(['permission:application,manage', 'throttle:10,1']);

// Web root: its own endpoint, not a field on update. It creates a directory, rewrites the
// vhost and reloads, so it needs the throttle and failure envelope of a server mutation.
Route::put('/applications/{application}/web-root', [ApplicationWebRootController::class, 'update'])
    ->middleware(['permission:application,manage', 'throttle:10,1']);

// Its own sub-resource and a 202: the switch restarts the site and waits for
// it to answer on the new version, then switches back if it does not.
Route::put('/applications/{application}/node-version', [ApplicationNodeVersionController::class, 'update'])
    ->middleware(['permission:application,manage', 'throttle:10,1']);

// Container settings. Own sub-resource: applying rewrites the compose file and recreates the
// container (real downtime). Throttled like the other apply paths: each call runs
// `docker compose up -d`.
Route::put('/applications/{application}/container', [ApplicationContainerController::class, 'update'])
    ->middleware(['permission:app_container,manage', 'throttle:10,1']);

// The compose file this site runs. `view` reads it, `manage` replaces it (it can stop the
// site). A recipe's file holds its generated passwords in full on purpose: granting
// `app_compose` grants the whole file.
Route::get('/applications/{application}/container/compose', [ApplicationContainerController::class, 'compose'])
    ->middleware(['permission:app_compose', 'throttle:60,1']);

// Data for the Command-line access card's `ssh … docker exec` line. Gated like reading the
// compose file; the system users in it are filtered on `system_user` inside.
Route::get('/applications/{application}/container/access', [ApplicationContainerController::class, 'access'])
    ->middleware(['permission:app_compose', 'throttle:60,1']);

// Container login for the app's own system user: on, its SSH login opens this app's
// container. Gated on `system_user` manage, like the System Users switches it reuses.
Route::post('/applications/{application}/container/access', [ApplicationContainerController::class, 'enableAccess'])
    ->middleware(['permission:system_user,manage', 'throttle:10,1']);
Route::delete('/applications/{application}/container/access', [ApplicationContainerController::class, 'disableAccess'])
    ->middleware(['permission:system_user,manage', 'throttle:10,1']);

// Throttled like the other apply paths: each save recreates the container.
Route::put('/applications/{application}/container/compose', [ApplicationContainerController::class, 'updateCompose'])
    ->middleware(['permission:app_compose,manage', 'throttle:10,1']);

// Pull a newer image and recreate the container on it.
// `manage` on the application, not `docker,manage`: this changes what one site runs.
// Throttled harder: each call can be a multi-gigabyte download.
Route::post('/applications/{application}/container/pull', [ApplicationContainerController::class, 'pull'])
    ->middleware(['permission:app_container,manage', 'throttle:6,1']);

// The generated credentials: `manage`, not `view`, since reading a database password is not
// read-only. Throttled harder so a leaked token cannot enumerate every password on a site.
Route::get('/applications/{application}/container/secrets', [ApplicationContainerController::class, 'secrets'])
    ->middleware(['permission:app_container,manage', 'throttle:6,1']);

// "I have saved these." Its own endpoint: the panel cannot rotate a generated credential, so
// hiding the first-run card as a side effect of rendering it would lose the password on a
// page refresh. Same permission as reading them.
// were only allowed to see under `manage`.
Route::post('/applications/{application}/container/secrets/acknowledge', [ApplicationContainerController::class, 'acknowledgeSecrets'])
    ->middleware(['permission:app_container,manage', 'throttle:20,1']);

// Site type: detect reads the disk and records what is installed; update relabels the site.
// Its own sub-resource, not a field on the generic update, because only some directions are
// allowed (see UpdateSiteTypeRequest). Throttled: detection probes the site directory and
// apply republishes and reloads the web server config.
Route::post('/applications/{application}/detect-type', [ApplicationSiteTypeController::class, 'detect'])
    ->middleware(['permission:application,manage', 'throttle:10,1']);
Route::put('/applications/{application}/site-type', [ApplicationSiteTypeController::class, 'update'])
    ->middleware(['permission:application,manage', 'throttle:10,1']);

// Deploy-on-push. The delivery endpoint itself is unauthenticated and lives in
// routes/api/webhooks.php; these two only configure it.
Route::get('/webhook-providers', [ApplicationWebhookController::class, 'providers'])
    ->middleware('permission:application');
// `app_deployment`, not `application`: this configures the Deployment screen, and the
// site-type check only runs for `app_`-prefixed permissions. Otherwise deploy-on-push could be
// switched on for a WordPress site that has no repository.
Route::put('/applications/{application}/webhook', [ApplicationWebhookController::class, 'update'])
    ->middleware('permission:app_deployment,manage');
Route::delete('/applications/{application}', [ApplicationController::class, 'destroy'])->middleware('permission:application,manage');

// Domains. Gated by `app_domain` (application-level), not the server-level `application`:
// sharing a permission across the two sidebars turns a narrow grant into a wide one.
Route::get('/applications/{application}/domains', [ApplicationDomainController::class, 'index'])
    ->middleware('permission:app_domain');
Route::post('/applications/{application}/domains', [ApplicationDomainController::class, 'store'])
    ->middleware('permission:app_domain,manage');
// Changes what a name does, never what it is called: a rename would leave the old name on the
// certificate, and `certbot renew` fails a whole lineage when one name cannot be validated.
Route::put('/applications/{application}/domains/{domain}', [ApplicationDomainController::class, 'update'])
    ->middleware('permission:app_domain,manage');
Route::post('/applications/{application}/domains/{domain}/verify', [ApplicationDomainController::class, 'verify'])
    ->middleware('permission:app_domain');
Route::post('/applications/{application}/domains/{domain}/primary', [ApplicationDomainController::class, 'makePrimary'])
    ->middleware('permission:app_domain,manage');
Route::delete('/applications/{application}/domains/{domain}', [ApplicationDomainController::class, 'destroy'])
    ->middleware('permission:app_domain,manage');

// Certificates share `app_domain` with the names they cover, so nobody can add a domain
// but not secure it.
Route::get('/applications/{application}/certificate', [CertificateController::class, 'show'])
    ->middleware('permission:app_domain');
Route::post('/applications/{application}/certificate', [CertificateController::class, 'store'])
    ->middleware('permission:app_domain,manage');
// The rehearsal. `manage` on the POST: it writes a challenge token to the server and makes
// this box talk to Let's Encrypt. Reading the verdict is a view.
Route::post('/applications/{application}/certificate/dry-run', [CertificateController::class, 'dryRun'])
    ->middleware('permission:app_domain,manage');
Route::get('/applications/{application}/certificate/dry-run', [CertificateController::class, 'dryRunStatus'])
    ->middleware('permission:app_domain');
Route::put('/applications/{application}/certificate/force-https', [CertificateController::class, 'forceHttps'])
    ->middleware('permission:app_domain,manage');
Route::delete('/applications/{application}/certificate', [CertificateController::class, 'destroy'])
    ->middleware('permission:app_domain,manage');

// Magic Login — WordPress only; the middleware enforces that (`app_magic_login` is in
// WordPressSiteType::features() only), so other types 404.
// `manage` on both, including the list: listing a site's administrators is not a view-level fact.
Route::get('/applications/{application}/magic-login', [MagicLoginController::class, 'index'])
    ->middleware('permission:app_magic_login,manage');
Route::post('/applications/{application}/magic-login', [MagicLoginController::class, 'store'])
    ->middleware('permission:app_magic_login,manage');

// The Deployment screen. `app_deployment` also brings the site-type check: a WordPress
// site has no repository, so these 404 there.
Route::get('/applications/{application}/deployments', [DeploymentController::class, 'index'])
    ->middleware('permission:app_deployment');
Route::post('/applications/{application}/deployments', [DeploymentController::class, 'store'])
    ->middleware('permission:app_deployment,manage');
// Polled every few seconds while the Deployment screen is open. Before the {deployment}
// route, which would take `latest` for an id and 404.
Route::get('/applications/{application}/deployments/latest', [DeploymentController::class, 'latest'])
    ->withoutMiddleware('throttle:api')
    ->middleware(['permission:app_deployment', 'throttle:progress']);
// Polled line-by-line while a deploy runs; same throttling as `latest`.
Route::get('/applications/{application}/deployments/{deployment}', [DeploymentController::class, 'show'])
    ->withoutMiddleware('throttle:api')
    ->middleware(['permission:app_deployment', 'throttle:progress']);
Route::post('/applications/{application}/deployments/{deployment}/redeploy', [DeploymentController::class, 'redeploy'])
    ->middleware('permission:app_deployment,manage');
Route::put('/applications/{application}/deployment-settings', [DeploymentController::class, 'updateSettings'])
    ->middleware('permission:app_deployment,manage');

// Re-point the site at a different account, repository or public URL. Not a settings write:
// the candidate credential is checked against the repository before anything is stored.
// Needed because disconnecting a git account nulls this column on every site that used it.
// Throttled: each call costs a request to the provider's API.
Route::put('/applications/{application}/git-account', [DeploymentController::class, 'updateGitAccount'])
    ->middleware(['permission:app_deployment,manage', 'throttle:30,1']);

// Measuring is expensive (`du` walks every inode), so it is throttled harder. Nothing else
// recomputes it: the shown size is the one last asked for. `manage` because it stores a new
// size and spends the server's disk time.
Route::post('/applications/{application}/directory-size', [ApplicationController::class, 'measureDirectorySize'])
    ->middleware(['permission:application,manage', 'throttle:10,1']);
