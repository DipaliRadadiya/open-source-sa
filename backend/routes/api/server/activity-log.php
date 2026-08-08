<?php

use App\Http\Controllers\API\ActivityLogController;
use Illuminate\Support\Facades\Route;

/*
| Server-scoped activity log.
|
| `activity_log` permission — different from `access-admin` which gates the
| admin-wide log. Server-level events are those with no per-application FK:
| cronjobs, disk_cleaner, service, fail2ban, firewall, git_account, node,
| setting, panel_update. Per-app events (application, database, backup) are
| surfaced through their own feature, not here.
|
| Path is /server/activity-log (not /activity-log) to avoid shadowing the
| self-only log at api/activity-log.
*/

Route::get('/server/activity-log', [ActivityLogController::class, 'serverIndex'])
    ->middleware('permission:activity_log');
