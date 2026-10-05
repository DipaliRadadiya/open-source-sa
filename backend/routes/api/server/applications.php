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
// The two the app screen polls while provisioning runs, which is why they drop
// the global limiter rather than sharing it: a per-route throttle stacks with
// `throttle:api` instead of replacing it, so polling used to spend the same
// budget as everything else the user was doing and a long install ended in a
// 429 that looked like the install had failed. `throttle:progress` is what
// bounds them now.
Route::get('/applications/{application}', [ApplicationController::class, 'show'])
    ->withoutMiddleware('throttle:api')
    ->middleware(['permission:application', 'throttle:progress']);
Route::get('/applications/{application}/sidebar', [ApplicationController::class, 'sidebar'])
    ->withoutMiddleware('throttle:api')
    ->middleware(['permission:application', 'throttle:progress']);
Route::put('/applications/{application}', [ApplicationController::class, 'update'])->middleware('permission:application,manage');
Route::post('/applications/{application}/provision', [ApplicationController::class, 'provision'])->middleware('permission:application,manage');
// Deploy is the Deployment screen's action, so it is gated by that screen's
// permission rather than the server-level `application` one. That also brings
// it under the site-type check: a WordPress install has no repository, so the
// endpoint 404s rather than running against a site that cannot deploy.
Route::post('/applications/{application}/deploy', [ApplicationController::class, 'deploy'])->middleware('permission:app_deployment,manage');
Route::post('/applications/{application}/process/{action}', [ApplicationController::class, 'process'])
    ->middleware('permission:application,manage');

// Switching an adopted application onto a systemd unit of ours. Its own
// endpoint rather than a field on update, because it **restarts the
// application** — one supervisor has to release the port before the other can
// bind it — and that is not something an ordinary save should do as a side
// effect. Throttled like the other actions that touch a running process.
Route::post('/applications/{application}/supervisor/convert', [ApplicationController::class, 'convertSupervisor'])
    ->middleware(['permission:application,manage', 'throttle:10,1']);

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
// The site folder's lock against its own user. A Lock button for sites that
// server sync adopted, whose folder the panel did not create.
Route::get('/applications/{application}/root-lock', [ApplicationRootLockController::class, 'show'])
    ->middleware('permission:application');
Route::post('/applications/{application}/root-lock', [ApplicationRootLockController::class, 'store'])
    ->middleware(['permission:application,manage', 'throttle:10,1']);

Route::put('/applications/{application}/web-root', [ApplicationWebRootController::class, 'update'])
    ->middleware(['permission:application,manage', 'throttle:10,1']);

// Its own sub-resource and a 202: the switch restarts the site and waits for
// it to answer on the new version, then switches back if it does not.
Route::put('/applications/{application}/node-version', [ApplicationNodeVersionController::class, 'update'])
    ->middleware(['permission:application,manage', 'throttle:10,1']);

// Container settings. Its own sub-resource for the same reason web-root is one:
// applying it rewrites the compose file and recreates the container, which is a
// server mutation with real downtime, not a field write.
//
// Throttled at the same rate as the other apply paths — each call runs
// `docker compose up -d`, and a form that can be spammed is a site that can be
// restarted in a loop.
Route::put('/applications/{application}/container', [ApplicationContainerController::class, 'update'])
    ->middleware(['permission:app_container,manage', 'throttle:10,1']);

// The compose file this site runs.
//
// `view` to read it and `manage` to replace it, unlike the settings endpoint which
// is manage-only: reading the file is how somebody diagnoses their own site, and it
// carries nothing the application payload does not already imply. Writing it is a
// different matter — it can stop the site.
Route::get('/applications/{application}/container/compose', [ApplicationContainerController::class, 'compose'])
    ->middleware(['permission:app_compose', 'throttle:60,1']);

// Throttled like the other apply paths: each save recreates the container, and a
// form that can be spammed is a site that can be restarted in a loop.
Route::put('/applications/{application}/container/compose', [ApplicationContainerController::class, 'updateCompose'])
    ->middleware(['permission:app_compose,manage', 'throttle:10,1']);

// Pull a newer image and recreate the container on it.
//
// `manage` on the application, not `docker,manage`: this changes what one site
// runs, which is the site's own permission — the same call the settings endpoint
// above makes. Gating it on Docker would mean somebody who may restart a site may
// not update it.
//
// Throttled harder than the settings write. Each call can be a multi-gigabyte
// download, so this is the one container endpoint where a spammed form costs
// bandwidth rather than a restart.
Route::post('/applications/{application}/container/pull', [ApplicationContainerController::class, 'pull'])
    ->middleware(['permission:app_container,manage', 'throttle:6,1']);

// The generated credentials, behind `manage` rather than `view`: reading a
// database password is not a read-only act in any sense that matters, and the
// permission that governs changing the site is the one that should govern seeing
// what it was built with.
//
// Throttled harder than the write above. A write is something a person does once;
// a GET that returns every password on a site is worth rate-limiting against a
// token that has leaked.
Route::get('/applications/{application}/container/secrets', [ApplicationContainerController::class, 'secrets'])
    ->middleware(['permission:app_container,manage', 'throttle:6,1']);

// "I have saved these." Its own endpoint because the panel cannot rotate a
// generated credential — that means rewriting the compose file and the credential
// inside the running database — so hiding the first-run card as a side effect of
// rendering it would lose an unrecoverable password to a page refresh. Only a
// person can say they have it.
//
// Same permission as reading them: acknowledging is a statement about values you
// were only allowed to see under `manage`.
Route::post('/applications/{application}/container/secrets/acknowledge', [ApplicationContainerController::class, 'acknowledgeSecrets'])
    ->middleware(['permission:app_container,manage', 'throttle:20,1']);

// Site type. Read the disk to find out what is installed, then relabel the
// site to match.
//
// Two endpoints because they are two different things: the first reads and
// records, the second changes what the panel offers. Its own sub-resource
// rather than a field on the generic update, for the same reason web-root is
// one — a general update would advertise a freely editable field and honour it
// only in some directions, and which directions are allowed is the whole
// feature (see UpdateSiteTypeRequest).
//
// Throttled: detection spawns file probes against a site directory, and the
// apply path republishes and reloads the web server's config.
Route::post('/applications/{application}/detect-type', [ApplicationSiteTypeController::class, 'detect'])
    ->middleware(['permission:application,manage', 'throttle:10,1']);
Route::put('/applications/{application}/site-type', [ApplicationSiteTypeController::class, 'update'])
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
// Changes what a name does, never what it is called. A rename would leave the
// old name on the certificate, and `certbot renew` fails a whole lineage when
// one name in it cannot be validated — so it stays a delete plus an add, which
// is visibly two decisions.
Route::put('/applications/{application}/domains/{domain}', [ApplicationDomainController::class, 'update'])
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
// The rehearsal. `manage` on the POST rather than plain read access: it writes
// a challenge token onto the server and makes this box talk to Let's Encrypt,
// which is not something a read-only viewer should be able to set off. Reading
// the verdict is a view.
Route::post('/applications/{application}/certificate/dry-run', [CertificateController::class, 'dryRun'])
    ->middleware('permission:app_domain,manage');
Route::get('/applications/{application}/certificate/dry-run', [CertificateController::class, 'dryRunStatus'])
    ->middleware('permission:app_domain');
Route::put('/applications/{application}/certificate/force-https', [CertificateController::class, 'forceHttps'])
    ->middleware('permission:app_domain,manage');
Route::delete('/applications/{application}/certificate', [CertificateController::class, 'destroy'])
    ->middleware('permission:app_domain,manage');

// Magic Login — WordPress only, and the middleware enforces that rather than
// the controller: `app_magic_login` is in WordPressSiteType::features() and no
// other type's, so every other site 404s here without a line of its own.
//
// `manage` on both, including the list. Reading which accounts are
// administrators of a customer's site is not a view-level fact, and the list
// exists for no purpose other than to then assume one of them.
Route::get('/applications/{application}/magic-login', [MagicLoginController::class, 'index'])
    ->middleware('permission:app_magic_login,manage');
Route::post('/applications/{application}/magic-login', [MagicLoginController::class, 'store'])
    ->middleware('permission:app_magic_login,manage');

// The Deployment screen. Gated by `app_deployment`, which also brings these
// under the site-type check — a WordPress install has no repository, so every
// one of them 404s there rather than running against a site that cannot deploy.
Route::get('/applications/{application}/deployments', [DeploymentController::class, 'index'])
    ->middleware('permission:app_deployment');
Route::post('/applications/{application}/deployments', [DeploymentController::class, 'store'])
    ->middleware('permission:app_deployment,manage');
// Polled every few seconds while the Deployment screen is open, so a deploy
// started by a push appears there without a reload. Before the {deployment}
// route, which would otherwise take `latest` for an id and 404.
Route::get('/applications/{application}/deployments/latest', [DeploymentController::class, 'latest'])
    ->withoutMiddleware('throttle:api')
    ->middleware(['permission:app_deployment', 'throttle:progress']);
// Polled line-by-line while a deploy runs — same reasoning as the two above.
Route::get('/applications/{application}/deployments/{deployment}', [DeploymentController::class, 'show'])
    ->withoutMiddleware('throttle:api')
    ->middleware(['permission:app_deployment', 'throttle:progress']);
Route::post('/applications/{application}/deployments/{deployment}/redeploy', [DeploymentController::class, 'redeploy'])
    ->middleware('permission:app_deployment,manage');
Route::put('/applications/{application}/deployment-settings', [DeploymentController::class, 'updateSettings'])
    ->middleware('permission:app_deployment,manage');

// Re-point the site at a different account, repository or public URL.
//
// Separate from `deployment-settings` because it is not a settings write: the
// candidate credential is asked whether it can reach the repository before
// anything is stored. Disconnecting a git account is allowed to succeed and
// nulls this column on every site that used it, so without this endpoint a
// deleted account left its applications permanently unbuildable — recoverable
// only by deleting and recreating the site.
//
// Throttled: each call costs a request to the provider's API.
Route::put('/applications/{application}/git-account', [DeploymentController::class, 'updateGitAccount'])
    ->middleware(['permission:app_deployment,manage', 'throttle:30,1']);

// Measuring is a read, but an expensive one — `du` walks every inode on the
// site — so it is gated by view permission and throttled harder than the
// screen around it. Nothing else recomputes this: not the listing, not a
// schedule. The size the panel shows is the one somebody last asked for.
Route::post('/applications/{application}/directory-size', [ApplicationController::class, 'measureDirectorySize'])
    ->middleware(['permission:application', 'throttle:10,1']);
