<?php

use App\Http\Controllers\API\Server\ApplicationController;
use App\Http\Controllers\API\Server\ApplicationDomainController;
use App\Http\Controllers\API\Server\ApplicationWebhookController;
use App\Http\Controllers\API\Server\ApplicationWebRootController;
use App\Http\Controllers\API\Server\CertificateController;
use App\Http\Controllers\API\Server\DeploymentController;
use App\Http\Controllers\API\Server\ServerCapabilityController;
use App\Http\Controllers\API\Server\SiteTypeController;
use Illuminate\Support\Facades\Route;

// Applications (server panel). Reads gated by `application` (view), mutations
// by `application` (manage).
//
// Phase 1: the catalog and the record only — nothing here writes to the
// server. A created application stays at `pending` until provisioning lands.

// What the server is and can run — drives which site types are offered.
Route::get('/server/capabilities', [ServerCapabilityController::class, 'index'])->middleware('permission:application');

Route::get('/site-types', [SiteTypeController::class, 'index'])->middleware('permission:application');

Route::get('/applications', [ApplicationController::class, 'index'])->middleware('permission:application');
Route::post('/applications', [ApplicationController::class, 'store'])->middleware('permission:application,manage');
Route::get('/applications/port-check', [ApplicationController::class, 'portCheck'])
    ->middleware('permission:application');
Route::get('/applications/{application}', [ApplicationController::class, 'show'])->middleware('permission:application');
Route::get('/applications/{application}/sidebar', [ApplicationController::class, 'sidebar'])->middleware('permission:application');
Route::put('/applications/{application}', [ApplicationController::class, 'update'])->middleware('permission:application,manage');
Route::post('/applications/{application}/provision', [ApplicationController::class, 'provision'])->middleware('permission:application,manage');
// Deploy is the Deployment screen's action, so it is gated by that screen's
// permission rather than the server-level `application` one. That also brings
// it under the site-type check: a WordPress install has no repository, so the
// endpoint 404s rather than running against a site that cannot deploy.
Route::post('/applications/{application}/deploy', [ApplicationController::class, 'deploy'])->middleware('permission:app_deployment,manage');
Route::post('/applications/{application}/process/{action}', [ApplicationController::class, 'process'])
    ->middleware('permission:application,manage');

// Enable/disable: a Dashboard action, not a separate screen, so it stays on
// the same `application` permission as the rest of this resource rather than
// a new one.
Route::post('/applications/{application}/disable', [ApplicationController::class, 'disable'])
    ->middleware(['permission:application,manage', 'throttle:10,1']);
Route::post('/applications/{application}/enable', [ApplicationController::class, 'enable'])
    ->middleware(['permission:application,manage', 'throttle:10,1']);

// Web root. Its own endpoint rather than a field on the generic update,
// because it is a server mutation — it creates a directory, rewrites the
// vhost and reloads — and needs the throttle and the failure envelope that
// go with one, not the plain-record semantics of `PUT /applications/{id}`.
Route::put('/applications/{application}/web-root', [ApplicationWebRootController::class, 'update'])
    ->middleware(['permission:application,manage', 'throttle:10,1']);

// Deploy-on-push. The delivery endpoint itself is unauthenticated and lives in
// routes/api/webhooks.php; these two only configure it.
Route::get('/webhook-providers', [ApplicationWebhookController::class, 'providers'])
    ->middleware('permission:application');
// `app_deployment`, not `application`: this configures the Deployment screen,
// and gating it on the server-level permission had two consequences. It let
// someone who owns that screen fail to configure its webhook while someone who
// cannot see the screen at all could — and, because the site-type check in
// CheckPermission only runs for `app_`-prefixed permissions, it allowed
// deploy-on-push to be switched on for a WordPress site that has no repository
// to push to.
Route::put('/applications/{application}/webhook', [ApplicationWebhookController::class, 'update'])
    ->middleware('permission:app_deployment,manage');
Route::delete('/applications/{application}', [ApplicationController::class, 'destroy'])->middleware('permission:application,manage');

// Domains. Gated by `app_domain` — an application-level permission, not the
// server-level `application`, because these are two different sidebars and
// sharing a permission across that line is how a narrow grant turns into a
// wide one.
Route::get('/applications/{application}/domains', [ApplicationDomainController::class, 'index'])
    ->middleware('permission:app_domain');
Route::post('/applications/{application}/domains', [ApplicationDomainController::class, 'store'])
    ->middleware('permission:app_domain,manage');
Route::post('/applications/{application}/domains/{domain}/verify', [ApplicationDomainController::class, 'verify'])
    ->middleware('permission:app_domain');
Route::post('/applications/{application}/domains/{domain}/primary', [ApplicationDomainController::class, 'makePrimary'])
    ->middleware('permission:app_domain,manage');
Route::delete('/applications/{application}/domains/{domain}', [ApplicationDomainController::class, 'destroy'])
    ->middleware('permission:app_domain,manage');

// Certificates live under the same `app_domain` permission as the names they
// cover. Two permissions would let someone add a domain but not secure it,
// which is not a state anybody wants to be in — and Forge's own 2025 redesign
// merged the two screens for the same reason.
Route::get('/applications/{application}/certificate', [CertificateController::class, 'show'])
    ->middleware('permission:app_domain');
Route::post('/applications/{application}/certificate', [CertificateController::class, 'store'])
    ->middleware('permission:app_domain,manage');
Route::put('/applications/{application}/certificate/force-https', [CertificateController::class, 'forceHttps'])
    ->middleware('permission:app_domain,manage');
Route::delete('/applications/{application}/certificate', [CertificateController::class, 'destroy'])
    ->middleware('permission:app_domain,manage');

// The Deployment screen. Gated by `app_deployment`, which also brings these
// under the site-type check — a WordPress install has no repository, so every
// one of them 404s there rather than running against a site that cannot deploy.
Route::get('/applications/{application}/deployments', [DeploymentController::class, 'index'])
    ->middleware('permission:app_deployment');
Route::post('/applications/{application}/deployments', [DeploymentController::class, 'store'])
    ->middleware('permission:app_deployment,manage');
Route::get('/applications/{application}/deployments/{deployment}', [DeploymentController::class, 'show'])
    ->middleware('permission:app_deployment');
Route::post('/applications/{application}/deployments/{deployment}/redeploy', [DeploymentController::class, 'redeploy'])
    ->middleware('permission:app_deployment,manage');
Route::put('/applications/{application}/deployment-settings', [DeploymentController::class, 'updateSettings'])
    ->middleware('permission:app_deployment,manage');

// Rollback: repoints the `current` symlink to the previous release directory.
// Idempotent — running twice rolls back two generations. Gated the same as deploy.
Route::post('/applications/{application}/rollback', [DeploymentController::class, 'rollback'])
    ->middleware(['permission:app_deployment,manage', 'throttle:10,1']);
