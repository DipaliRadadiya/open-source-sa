# ServerAvatar OSS — API Reference

**Base URL:** `https://sv-oss.167-233-229-184.nip.io/api`

**Auth:** Bearer token (Sanctum). Cookie-based session also supported for browser clients. Send `Accept-Language` for localised error messages.

**Response envelope:** named top-level key, never a generic `data` wrapper.
```json
{"user": {"id": 1, "username": "admin", …}}
{"applications": [{"id": 1, "name": "shop", …}]}
```

**Errors:**

| Status | Shape | When |
|--------|-------|------|
| 401 | `{"message": "Unauthenticated."}` | Missing or invalid token |
| 403 | `{"message": "…"}` | Permission denied |
| 404 | `{"message": "Not Found."}` | Resource not found |
| 422 | `{"message": "…", "errors": {"field": ["…"]}}` | Validation failure |
| 500 | `{"message": "…", "reference": "…"}` | Server-op failure; `reference` locates the log entry |

**Timestamps:** `DD-MM-YYYY HH:mm:ss` + `_human` sibling (e.g. `created_at_human: "3 minutes ago"`).

**Pagination:** page-based. Response includes `meta: {current_page, per_page, total, last_page}`. Pass `?page=N&per_page=20`.

**Permissions:** read operations need `view` ability; mutations need `manage`. Middleware notation: `permission:<name>` or `permission:<name>,manage`.

---

## Auth

### POST `/auth/register`
Create the first admin account. Registration closes after this call.

**Request:**
```json
{"username": "admin", "password": "…"}
```

**Response `201`:**
```json
{"user": {"id": 1, "username": "admin", "is_admin": true, "created_at": "23-07-2026 10:00:00", "created_at_human": "3 weeks ago"}}
```

---

### POST `/auth/login`
```json
{"username": "admin", "password": "…"}
```

**Response `200`:** Sets session cookie. Returns the user object.
```json
{"user": {"id": 1, "username": "admin", "is_admin": true, "roles": [{"id": 1, "name": "Administrator", "slug": "administrator"}], "created_at": "23-07-2026 10:00:00", "created_at_human": "3 weeks ago"}}
```

---

### POST `/auth/logout`
Auth-gated. Destroys the session/token.

**Response `204`:** `null`

---

### GET `/auth/me`
Auth-gated. Returns the current user + `impersonated_by` if an admin is currently viewing as this user.

```json
{"user": {
  "id": 1, "username": "admin", "is_admin": true,
  "roles": [{"id": 1, "name": "Administrator", "slug": "administrator"}],
  "impersonated_by": null,
  "created_at": "23-07-2026 10:00:00", "created_at_human": "3 weeks ago"
}}
```

---

### PUT `/auth/profile`
Auth-gated. Update own username.

**Request:** `{"username": "newusername"}`

**Response `200`:** `{"user": {...updated...}}`

---

### PUT `/auth/password`
Auth-gated. Change own password.

**Request:** `{"current_password": "…", "password": "…", "password_confirmation": "…"}`

**Response `200`:** `{"message": "Password updated."}`

---

### POST `/auth/stop-impersonating`
Auth-gated. Exit impersonation mode (admin feature).

**Response `200`:** `{"user": {…}}`

---

## Admin — Roles

### GET `/admin/roles`
**Permission:** `access-admin` (view)

```json
{"roles": [{"id": 1, "name": "Administrator", "slug": "administrator", "is_system": true, "permissions": [...], "created_at": "…"}]}
```

`is_system: true` — system roles cannot be renamed, deleted, or have their permissions changed (422).

---

### POST `/admin/roles`
**Permission:** `access-admin` (manage)

**Request:** `{"name": "Developer", "permissions": ["application", "database", "logs"]}`

**Response `201`:** `{"role": {...}}`

---

### GET `/admin/roles/{role}`
**Permission:** `access-admin` (view)

```json
{"role": {"id": 2, "name": "Developer", "slug": "developer", "is_system": false, "permissions": ["application", "database", "logs"], "users_count": 3, "created_at": "…"}}
```

---

### PUT `/admin/roles/{role}`
**Permission:** `access-admin` (manage)

**Request:** `{"name": "DevOps", "permissions": ["application", "database", "logs", "cronjob"]}`

**Response `200`:** `{"role": {...}}`

`422` if the role is `is_system: true`.

---

### DELETE `/admin/roles/{role}`
**Permission:** `access-admin` (manage)

**Response `204`:** `null`

`422` if the role is `is_system: true` or has assigned users.

---

## Admin — Users

### GET `/admin/users`
**Permission:** `access-admin` (view)

Paginated. Filter by `filter[role_id]`, `filter[search]` (username).

```json
{"users": [{"id": 1, "username": "admin", "is_admin": true, "roles": [...], "created_at": "…", "last_active_at": "…"}], "meta": {"current_page": 1, "per_page": 20, "total": 3, "last_page": 1}}
```

---

### POST `/admin/users`
**Permission:** `access-admin` (manage)

**Request:**
```json
{"username": "dev", "password": "…", "role_ids": [2]}
```

**Response `201`:** `{"user": {...}}`

---

### GET `/admin/users/{user}`
**Permission:** `access-admin` (view)

```json
{"user": {"id": 2, "username": "dev", "is_admin": false, "roles": [{"id": 2, "name": "Developer"}], "created_at": "…", "last_active_at": null}}
```

---

### PUT `/admin/users/{user}`
**Permission:** `access-admin` (manage)

**Request:** `{"username": "senior-dev", "role_ids": [2, 3]}`

**Response `200`:** `{"user": {...}}`

---

### DELETE `/admin/users/{user}`
**Permission:** `access-admin` (manage)

**Response `204`:** `null`

Cannot delete yourself (422).

---

### PUT `/admin/users/{user}/password`
**Permission:** `access-admin` (manage)

**Request:** `{"password": "…"}`

**Response `200`:** `{"message": "Password reset."}`

---

### PUT `/admin/users/{user}/roles`
**Permission:** `access-admin` (manage)

**Request:** `{"role_ids": [1, 2]}`

**Response `200`:** `{"user": {"id": 2, "roles": [...]}}`

---

### POST `/admin/users/{user}/impersonate`
**Permission:** `access-admin` (manage)

Become this user for the session (admin feature). Cannot impersonate yourself or another admin.

**Response `200`:** `{"user": {…}, "impersonated_by": {"id": 1, "username": "admin"}}`

---

## Admin — Permissions Catalog

### GET `/admin/permissions`
**Permission:** `access-admin` (view)

Returns the full flat permission catalog. Used to build the role assignment form.

```json
{"permissions": [
  {"id": 1, "name": "dashboard", "title": "Dashboard", "level": "server", "sub_level": "server"},
  {"id": 2, "name": "application", "title": "Applications", "level": "server", "sub_level": "server"},
  …
]}
```

Grouped in the UI by `level` → `sub_level`. `sub_level_title` is localised.

---

## Admin — Activity Log (admin-wide)

### GET `/admin/activity-log/filters`
**Permission:** `access-admin` (view)

Returns every `type` and `action` value the system has ever recorded, for building filter dropdowns.

```json
{"types": ["user", "role", "system_user", "application", "database", …], "actions": {"all": ["created", "updated", "deleted", …], "user": ["registered", "logged_in", "password_changed", …]}}
```

---

### GET `/admin/activity-log`
**Permission:** `access-admin` (view)

Paginated. Filters: `filter[user_id]`, `filter[type]`, `filter[action]`, `search` (free-text on type + action + actor name).

```json
{"activity_log": [{"id": 1, "type": "user", "action": "registered", "description": "Registered", "user": {"id": 1, "username": "admin"}, "properties": {}, "created_at": "…", "created_at_human": "2 hours ago"}], "meta": {"current_page": 1, "per_page": 20, "total": 150, "last_page": 8}}
```

`description` is built at read time from `__('activity.'.$type.'.'.$action, $properties)` in the viewer's locale — not stored.

---

## Admin — Server Dashboard

### GET `/admin/dashboard`
**Permission:** `access-admin` (view)

```json
{"dashboard": {
  "total_users": 3, "total_applications": 7, "total_databases": 4,
  "server_uptime": "15 days", "server_uptime_seconds": 1296000,
  "recent_activity": [{"type": "application", "action": "created", "description": "Application created", "user": {"id": 1, "username": "admin"}, "created_at": "…"}]
}}
```

---

## Admin — Panel Updates

### GET `/admin/panel-updates`
**Permission:** `access-admin` (view)

```json
{"update": {
  "current_version": "1.0.0", "latest_version": "1.1.0",
  "update_available": true, "release_notes": "…",
  "changelog_url": "https://github.com/…/releases/1.1.0"
}}
```

---

### POST `/admin/panel-updates`
**Permission:** `access-admin` (manage)

Runs the panel update. Returns `202` while the update runs in the background.

```json
{"message": "Update started.", "reference": "…"}
```

---

## Activity Log (own history)

### GET `/activity-log/filters`
Auth-gated. Same shape as `/admin/activity-log/filters` but scoped to the authenticated user.

---

### GET `/activity-log`
Auth-gated. Own history only. No `user` field in each row (redundant — it's you).

---

## Branding

### GET `/branding`
Unauthenticated.

```json
{"branding": {"panel_name": "ServerAvatar", "logo_url": null}}
```

---

## Permissions Check

### GET `/permissions`
Auth-gated. Returns every permission the current user holds.

```json
{"permissions": ["dashboard", "application", {"name": "app_backup", "level": "application"}, …]}
```

---

### GET `/permissions/check`
Auth-gated. Check if the current user can perform an action.

**Query:** `?ability=application,manage`

**Response `200`:** `{"allowed": true}`

---

## Server — Capabilities

### GET `/server/capabilities`
**Permission:** `application` (view)

What this server can run — drives which site types are offered.

```json
{"capabilities": {
  "runtimes": {"php": ["8.4", "8.3"], "node": ["20", "18"]},
  "web_servers": ["nginx", "apache"],
  "database_engines": {"mysql": false, "mariadb": true, "mongodb": false}
}}
```

---

## Site Types (Application Catalog)

### GET `/site-types`
**Permission:** `application` (view)

One entry per installable site type. Each carries its own field schema — the frontend writes one generic form renderer.

```json
{"site_types": [{
  "name": "wordpress",
  "title": "WordPress",
  "tagline": "Popular CMS with plugin ecosystem",
  "icon": "WordPressIcon",
  "category": "blog",
  "popular": true,
  "serving_profile": "php",
  "needs_database": true,
  "available": true,
  "unavailable_reason": null,
  "installable_runtime": null,
  "has_installer": true,
  "fields": [
    {"name": "domain", "type": "domain", "label": "Domain", "required": true, "placeholder": "shop.example.com"},
    {"name": "php_version", "type": "select", "label": "PHP Version", "required": true, "options": ["8.4", "8.3", "8.2"]},
    {"name": "system_user_id", "type": "select", "label": "Owner", "required": true, "options": [{"value": 1, "label": "siteowner"}]},
    {"name": "site_user_password", "type": "password", "label": "Site Owner Password", "required": true},
    {"name": "web_root", "type": "text", "label": "Web Root", "required": false, "default": "/"}
  ]
}]}
```

Field `type` values: `text`, `password`, `select`, `domain`, `email`, `textarea`, `toggle`, `repository`.

`available: false` → card is greyed. `installable_runtime` names the missing runtime that would fix it.

---

## Applications

### GET `/applications`
**Permission:** `application` (view)

```json
{"applications": [{
  "id": 1, "name": "shop", "domain": "shop.example.com",
  "site_type": "wordpress", "serving_profile": "php",
  "system_user": {"id": 1, "username": "siteowner"},
  "status": "running", "php_version": "8.4",
  "repository": null, "branch": null,
  "created_at": "25-07-2026 14:30:00", "created_at_human": "2 weeks ago",
  "last_deployed_at": null
}]}
```

---

### POST `/applications`
**Permission:** `application` (manage)

Create + queue provisioning. Poll `GET /applications/{id}` until `status` leaves `provisioning`.

**Request:**
```json
{
  "name": "shop",
  "domain": "shop.example.com",
  "site_type": "wordpress",
  "php_version": "8.4",
  "system_user_id": 1,
  "site_user_password": "…",
  "web_root": "/",
  "git_account_id": null,
  "repository": null,
  "branch": null,
  "package_manager": null
}
```

**Response `201`:**
```json
{"application": {"id": 1, "name": "shop", …, "status": "provisioning"}}
```

---

### GET `/applications/{application}`
**Permission:** `application` (view)

Full application record. Poll this while `status` is `provisioning` or `deploying`.

```json
{"application": {
  "id": 1, "name": "shop", "domain": "shop.example.com",
  "site_type": "wordpress", "serving_profile": "php",
  "system_user": {"id": 1, "username": "siteowner"},
  "status": "running", "status_title": "Running",
  "php_version": "8.4",
  "app_port": null,
  "repository": null, "branch": null,
  "webhook_enabled": false,
  "auto_deploy": false,
  "package_manager": null,
  "node_version": null,
  "deploy_script": null,
  "deploy_script_customised": false,
  "last_commit": null, "last_deployed_at": null,
  "backup_target": {"id": 1, "storage_destination": {"id": 1, "name": "S3 Backup"}},
  "certificate": {"type": "letsencrypt", "expires_at": "01-09-2026 00:00:00"},
  "domains": [{"id": 1, "domain": "shop.example.com", "type": "primary", "verified": true}],
  "created_at": "25-07-2026 14:30:00", "created_at_human": "2 weeks ago"
}}
```

---

### GET `/applications/{application}/sidebar`
**Permission:** `application` (view)

Returns the sidebar nav items for this application, filtered by what this site type supports AND what the user can access.

```json
{"items": [
  {"id": "app_dashboard", "name": "app_dashboard", "title": "Dashboard", "icon": "LayoutDashboardIcon", "sub_level": "info", "url": "/applications/1"},
  {"id": "app_domain", "name": "app_domain", "title": "Domains & SSL", "icon": "GlobeIcon", "sub_level": "info"},
  {"id": "app_deployment", "name": "app_deployment", "title": "Deployment", "icon": "GitBranchIcon", "sub_level": "info"},
  …
]}
```

---

### PUT `/applications/{application}`
**Permission:** `application` (manage)

Update name / web root / PHP version / git branch.

**Request:** `{"name": "new-shop", "php_version": "8.4", "web_root": "/public", "branch": "main"}`

**Response `200`:** `{"application": {...}}`

---

### POST `/applications/{application}/provision`
**Permission:** `application` (manage)

Re-run provisioning after a failure. Dispatches `ProvisionApplication` job.

**Response `202`:** `{"application": {"id": 1, "status": "provisioning", "failed_step": null, "reference": null}}`

---

### POST `/applications/{application}/deploy`
**Permission:** `app_deployment` (manage)

Trigger a git deploy (git-deploy apps only). `422` for one-click types.

**Response `202`:** `{"application": {"id": 1, "status": "deploying"}}`

---

### POST `/applications/{application}/process/{action}`
**Permission:** `application` (manage)

Start / stop / restart the site's own process (Node apps only). `{action}` = `start | stop | restart`.

`422` if the app has no process (PHP sites).

**Response `200`:** `{"application": {...}}`

`500 {"message": "…", "reference": "…"}` on failure.

---

### POST `/applications/{application}/disable`
**Permission:** `application` (manage) | **Throttle:** 10/min

Swap vhost to an "unavailable" placeholder page. Files, database, and workers are untouched.

**Response `200`:** `{"application": {"id": 1, "status": "disabled"}}`

---

### POST `/applications/{application}/enable`
**Permission:** `application` (manage) | **Throttle:** 10/min

Reverse `disable` — restore the live vhost.

**Response `200`:** `{"application": {...}}`

---

### PUT `/applications/{application}/web-root`
**Permission:** `application` (manage) | **Throttle:** 10/min

Change the served directory (creates it if missing, rewrites vhost, tests + reloads).

**Request:** `{"web_root": "/public"}`

**Response `200`:** `{"application": {"id": 1, "web_root": "/public"}}`

---

### DELETE `/applications/{application}`
**Permission:** `application` (manage)

Delete the application record. Optionally delete files.

**Request body (all optional):** `{"remove_files": false}`

**Response `200`:** `{"deleted": true}`

---

### GET `/applications/port-check`
**Permission:** `application` (view)

Check if a port is available before creating an app.

**Query:** `?port=3000&application_id=1` (application_id excludes that app's own port from the check)

**Response `200`:**
```json
{"port_check": {
  "port": 3000, "status": "free", "reason": null,
  "message": "Port 3000 is available."
}}
```

`status: "warning"` + `reason: "known_service"` when free but matches a known service name (e.g. `3000 → grafana`).

---

## Application — Domains & SSL

### GET `/applications/{application}/domains`
**Permission:** `app_domain` (view)

```json
{"domains": [
  {"id": 1, "domain": "shop.example.com", "type": "primary", "verified": true, "verified_at": "26-07-2026 09:00:00", "created_at": "25-07-2026 14:30:00"}
]}
```

---

### POST `/applications/{application}/domains`
**Permission:** `app_domain` (manage)

**Request:** `{"domain": "www.example.com"}`

**Response `201`:** `{"domain": {"id": 2, "domain": "www.example.com", "type": "additional", "verified": false}}`

---

### POST `/applications/{application}/domains/{domain}/verify`
**Permission:** `app_domain` (view)

Re-check DNS for this domain.

**Response `200`:** `{"domain": {"id": 2, "domain": "www.example.com", "verified": true, "verified_at": "26-07-2026 09:05:00"}}`

---

### POST `/applications/{application}/domains/{domain}/primary`
**Permission:** `app_domain` (manage)

Promote this domain to primary (renames vhost + log files).

**Response `200`:** `{"domains": [...updated list with type reordered...]}`

---

### DELETE `/applications/{application}/domains/{domain}`
**Permission:** `app_domain` (manage)

**Response `204`:** `null`

---

## Application — Certificate

### GET `/applications/{application}/certificate`
**Permission:** `app_domain` (view)

`null` when no certificate is installed — normal state, not an error.

```json
{"certificate": {
  "type": "letsencrypt", "status": "active",
  "expires_at": "01-09-2026 00:00:00", "expires_at_human": "in 3 weeks",
  "issuer": "Let's Encrypt",
  "domains": ["shop.example.com", "www.example.com"],
  "created_at": "25-07-2026 14:31:00"
}}
```

**Response `200` with `certificate: null`:** no cert installed yet.

---

### POST `/applications/{application}/certificate`
**Permission:** `app_domain` (manage)

Issue a new certificate (queued, `202`) or upload an existing one (synchronous, `201`).

**Request:**
```json
{"type": "letsencrypt", "domains": ["shop.example.com", "www.example.com"], "force": false}
```
OR
```json
{"type": "custom", "cert": "-----BEGIN CERTIFICATE-----…", "key": "-----BEGIN PRIVATE KEY-----…", "chain": "-----BEGIN CERTIFICATE-----…"}
```

**Response `202`** (Let's Encrypt): `{"certificate": {"status": "pending", "type": "letsencrypt"}}`
**Response `201`** (upload): `{"certificate": {...}}`

---

### PUT `/applications/{application}/certificate/force-https`
**Permission:** `app_domain` (manage)

Force all HTTP traffic to HTTPS via a 301 redirect rule.

**Request:** `{"force_https": true}`

`422` if no certificate is installed.

**Response `200`:** `{"certificate": {"force_https": true}}`

---

### DELETE `/applications/{application}/certificate`
**Permission:** `app_domain` (manage)

Remove the certificate and its redirect rule.

**Response `204`:** `null`

---

## Application — Deployment

### GET `/applications/{application}/deployments`
**Permission:** `app_deployment` (view)

Newest first.

```json
{"deployments": [{
  "id": 12, "trigger": "manual", "trigger_title": "Manual deploy",
  "status": "completed", "status_title": "Completed",
  "branch": "main", "commit": "a1b2c3d",
  "commit_message": "Fix payment redirect",
  "started_at": "28-07-2026 11:00:00", "started_at_human": "3 days ago",
  "finished_at": "28-07-2026 11:01:30", "finished_at_human": "3 days ago",
  "output": "…build log…",
  "user": {"id": 1, "username": "admin"}
}], "settings": {
  "branch": "main", "repository": "https://github.com/user/shop",
  "deploy_script": "cd {path}\ngit pull origin {branch}\nnpm install\nnpm run build",
  "deploy_script_customised": true,
  "default_deploy_script": "cd {path}\ngit pull origin {branch}\ncomposer install --no-dev",
  "auto_deploy": false, "webhook_enabled": false,
  "last_commit": "a1b2c3d", "last_deployed_at": "28-07-2026 11:01:30",
  "placeholders": ["{path}", "{branch}", "{domain}"]
}}
```

---

### POST `/applications/{application}/deployments`
**Permission:** `app_deployment` (manage)

Start a deploy.

**Response `202`:** `{"deployment": {"id": 13, "status": "pending"}}`

---

### GET `/applications/{application}/deployments/{deployment}`
**Permission:** `app_deployment` (view)

One deployment with full build output.

```json
{"deployment": {
  "id": 12, "trigger": "manual", "status": "completed",
  "branch": "main", "commit": "a1b2c3d",
  "commit_message": "Fix payment redirect",
  "output": "…full build log…",
  "started_at": "28-07-2026 11:00:00", "finished_at": "28-07-2026 11:01:30",
  "user": {"id": 1, "username": "admin"}
}}
```

---

### POST `/applications/{application}/deployments/{deployment}/redeploy`
**Permission:** `app_deployment` (manage)

Re-run the same deployment (re-fetches current branch tip, re-runs build script).

**Response `202`:** `{"deployment": {"id": 14, "status": "pending"}}`

---

### PUT `/applications/{application}/deployment-settings`
**Permission:** `app_deployment` (manage)

Update branch, deploy script, auto-deploy toggle.

**Request:**
```json
{"branch": "develop", "deploy_script": "cd {path}\ngit pull origin {branch}\nnpm install\nnpm run build", "auto_deploy": true}
```

**Response `200`:** `{"settings": {...updated...}}`

---

## Application — Webhooks (deploy-on-push)

### GET `/webhook-providers`
**Permission:** `application` (view)

What each provider (GitHub/GitLab/Bitbucket) needs from the user and which direction the secret travels.

```json
{"webhook_providers": {
  "github": {"fields": ["repository", "branch"], "secret_direction": "panel_sets_secret"},
  "gitlab": {"fields": ["repository", "branch"], "secret_direction": "panel_sets_secret"},
  "bitbucket": {"fields": ["repository"], "secret_direction": "panel_sets_secret"}
}}
```

---

### PUT `/applications/{application}/webhook`
**Permission:** `app_deployment` (manage)

Configure deploy-on-push.

**Request:**
```json
{"provider": "github", "repository": "user/shop", "branch": "main", "secret": "…"}
```

**Response `200`:** `{"application": {"webhook_enabled": true, "webhook_identifier": "abc123"}}`

The `webhook_identifier` is the unique path segment the provider calls — displayed in the provider's webhook UI as the callback URL.

---

## Application — Issues (App Dashboard)

### GET `/applications/{application}/issues`
**Permission:** `app_dashboard` (view)

All health signals for this application in one call.

```json
{"issues": [
  {"id": "ssl_expiring", "severity": "warning", "title": "SSL certificate expires soon", "detail": "The SSL certificate for shop.example.com expires in 12 days.", "data": {"expires_at": "01-09-2026 00:00:00", "days_remaining": 12}},
  {"id": "no_recent_deploy", "severity": "info", "title": "No recent deployment", "detail": "Last deployed 30 days ago.", "data": {"last_deployed_at": null}}
], "healthy": false}
```

`healthy: false` when there is at least one warning or critical. Info-level issues do not affect the flag.

---

## Application — Environment (`.env` editor)

### GET `/applications/{application}/environment`
**Permission:** `app_environment` (view)

`403` for site types that don't use a `.env` (e.g. WordPress — it uses `wp-config.php`).

```json
{"environment": {
  "path": "/home/siteowner/shop.example.com/.env",
  "raw": "APP_NAME=Shop\nDB_HOST=127.0.0.1\n…",
  "backups": ["2026-07-28-141530", "2026-07-27-093000"],
  "variables": [
    {"key": "APP_NAME", "value": "Shop", "secret": false},
    {"key": "DB_PASSWORD", "value": "••••••••", "secret": true}
  ]
}}
```

`backups` — timestamped automatic snapshots before each save.

---

### PUT `/applications/{application}/environment`
**Permission:** `app_environment` (manage) | **Throttle:** 20/min

Replace the entire `.env` file. Optionally restart the app's process after save.

**Request:**
```json
{"raw": "APP_NAME=Shop\nDB_HOST=127.0.0.1\nDB_PASSWORD=secret123\n", "restart": true}
```

**Response `200`:**
```json
{"environment": {"path": "…", "raw": "…", "backups": ["2026-07-28-141530","2026-07-28-150000"], "variables": [...]}, "applied": true, "restarted": true}
```

`applied: true` — config cache was cleared (Laravel apps). `restarted: true` — the app's process was restarted.

Syntax errors return `422` with `errors.raw` listing the problem.

---

### POST `/applications/{application}/environment/restore`
**Permission:** `app_environment` (manage) | **Throttle:** 10/min

Restore a previous snapshot.

**Request:** `{"backup": "2026-07-28-141530", "restart": true}`

**Response `200`:** `{"environment": {...}}`

---

## Application — File Manager

Every operation runs as the site's own Linux user (not root). All paths are relative to the site's root.

### POST `/applications/{application}/fix-permissions`
**Permission:** `app_file` (manage) | **Throttle:** 5/min

Fix file/directory ownership and permissions for this site.

**Response `200`:** `{"fixed": true}`

---

### GET `/applications/{application}/files`
**Permission:** `app_file` (view)

Browse a directory.

**Query:** `?path=/wp-content/plugins` (defaults to `/`)

**Response `200`:**
```json
{"path": "/wp-content/plugins", "files": [
  {"name": "seo-pack", "type": "directory", "size": null, "modified": "27-07-2026 10:00:00", "permissions": "drwxr-xr-x"},
  {"name": "README.md", "type": "file", "size": 4096, "modified": "25-07-2026 14:30:00", "permissions": "-rw-r--r--"}
]}
```

---

### GET `/applications/{application}/files/search`
**Permission:** `app_file` (view) | **Throttle:** 10/min

Recursive filename search.

**Query:** `?path=/&search=config`

**Response `200`:**
```json
{"path": "/", "query": "config", "files": [
  {"name": "wp-config.php", "type": "file", "path": "/wp-config.php", "size": 4096, "modified": "25-07-2026 14:30:00"}
], "truncated": false}
```

`truncated: true` — results exceeded a server-side cap.

---

### GET `/applications/{application}/files/size`
**Permission:** `app_file` (view) | **Throttle:** 20/min

Folder size on disk.

**Query:** `?path=/wp-content`

**Response `200`:** `{"path": "/wp-content", "size": 52428800, "size_human": "50 MB"}`

---

### GET `/applications/{application}/files/content`
**Permission:** `app_file` (view) | **Throttle:** 60/min

Read a file.

**Query:** `?path=/wp-config.php`

**Response `200`:**
```json
{"path": "/wp-config.php", "content": "<?php\ndefine('DB_NAME', 'shop');\n…", "size": 4096, "backups": ["2026-07-28-141530"]}
```

Binary files return `422`.

---

### PUT `/applications/{application}/files/content`
**Permission:** `app_file` (manage) | **Throttle:** 20/min

Write/edit a file.

**Request:** `{"path": "/wp-config.php", "content": "<?php\n…"}`

**Response `200`:** `{"saved": true}`

---

### POST `/applications/{application}/files/content/restore`
**Permission:** `app_file` (manage) | **Throttle:** 10/min

Restore a file from an automatic backup.

**Request:** `{"path": "/wp-config.php", "backup": "2026-07-28-141530"}`

**Response `200`:** `{"restored": true}`

---

### POST `/applications/{application}/files/upload`
**Permission:** `app_file` (manage) | **Throttle:** 10/min | **Content-Type:** `multipart/form-data`

Upload a file.

**Body:** `file` (binary), `path` (destination directory, e.g. `/wp-content`)

**Response `200`:** `{"uploaded": true}`

---

### GET `/applications/{application}/files/download`
**Permission:** `app_file` (view) | **Throttle:** 20/min

Download a file. Response is a binary stream (`Content-Type: application/octet-stream`).

**Query:** `?path=/wp-content/seo-pack.zip`

---

### POST `/applications/{application}/files/extract`
**Permission:** `app_file` (manage) | **Throttle:** 5/min

Extract a `.zip`, `.tar.gz` or `.tar.bz2` archive.

**Request:** `{"archive_path": "/wp-content/themes.zip", "target_path": "/wp-content"}`

**Response `200`:** `{"extracted": true}`

---

### POST `/applications/{application}/files/directories`
**Permission:** `app_file` (manage) | **Throttle:** 20/min

Create a directory.

**Request:** `{"path": "/wp-content/new-plugin"}`

**Response `200`:** `{"created": true}`

---

### PUT `/applications/{application}/files/rename`
**Permission:** `app_file` (manage) | **Throttle:** 20/min

Rename or move a file/directory.

**Request:** `{"source_path": "/wp-content/old-name", "target_path": "/wp-content/new-name"}`

**Response `200`:** `{"renamed": true}`

---

### POST `/applications/{application}/files/copy`
**Permission:** `app_file` (manage) | **Throttle:** 10/min

Copy a file.

**Request:** `{"source_path": "/wp-config.php", "target_path": "/wp-config.php.bak"}`

**Response `200`:** `{"copied": true}`

---

### POST `/applications/{application}/files/compress`
**Permission:** `app_file` (manage) | **Throttle:** 10/min

Compress files/directories into a `.tar.gz`.

**Request:** `{"source_path": "/wp-content", "target_path": "/wp-content-backup.tar.gz"}`

**Response `200`:** `{"compressed": true}`

---

### PUT `/applications/{application}/files/permissions`
**Permission:** `app_file` (manage) | **Throttle:** 20/min

Change file/directory mode (e.g. `0644`, `0755`).

**Request:** `{"path": "/wp-config.php", "mode": "0644"}`

**Response `200`:** `{"chmoded": true}`

---

### DELETE `/applications/{application}/files`
**Permission:** `app_file` (manage) | **Throttle:** 10/min

Delete a file or directory. Requires `{"path": "…", "confirm": true}` in body.

**Request:** `{"path": "/wp-content/old-plugin", "confirm": true}`

**Response `200`:** `{"deleted": true}`

---

## Application — PHP Settings

### GET `/applications/{application}/php`
**Permission:** `app_php` (view)

`403` for non-PHP site types.

```json
{"php": {
  "isolated": true, "isolated_at": "25-07-2026 14:30:00",
  "php_version": "8.4",
  "memory_limit": "256M", "max_execution_time": 120,
  "upload_max_filesize": "64M", "post_max_size": "128M",
  "max_input_vars": 3000,
  "opcache_enabled": true, "opcache_revalidate_freq": 2
}}
```

`isolated: false` — site runs on the shared PHP-FPM pool.

---

### PUT `/applications/{application}/php`
**Permission:** `app_php` (manage) | **Throttle:** 10/min

Update PHP version and/or pool settings.

**Request:**
```json
{"php_version": "8.4", "memory_limit": "512M", "max_execution_time": 300, "upload_max_filesize": "128M"}
```

**Response `200`:** `{"php": {...updated...}}`

---

### POST `/applications/{application}/php/isolate`
**Permission:** `app_php` (manage) | **Throttle:** 5/min

Give this site its own PHP-FPM pool running as its own user. Pre-condition for per-site memory limits.

**Response `200`:** `{"php": {"isolated": true, "isolated_at": "29-07-2026 10:00:00", …}}`

`422` if already isolated or if the web server is OpenLiteSpeed (OLS has no pools).

---

### DELETE `/applications/{application}/php/isolate`
**Permission:** `app_php` (manage) | **Throttle:** 5/min

Return to the shared pool (undoes isolation).

**Response `200`:** `{"php": {"isolated": false, …}}`

---

## Application — Basic Auth (Password Protection)

### PUT `/applications/{application}/security`
**Permission:** `app_security` (manage) | **Throttle:** 10/min

Enable/disable HTTP Basic Auth for this site.

**Request:**
```json
{"enabled": true, "username": "shopuser", "password": "secretpass"}
```
To disable: `{"enabled": false}`

**Response `200`:** `{"application": {"id": 1, "basic_auth_enabled": true}}`

---

## Application — Staging Area

WordPress only. Clone a site to a staging domain, work on it, push changes back.

### GET `/applications/{application}/staging`
**Permission:** `app_staging` (view)

```json
{"staging": {
  "id": 5, "name": "shop-staging", "domain": "staging.example.com",
  "status": "running", "php_version": "8.4",
  "system_user": {"id": 1, "username": "siteowner"},
  "created_at": "28-07-2026 09:00:00", "created_at_human": "3 days ago"
}}
```

`staging: null` — no staging site exists yet.

---

### POST `/applications/{application}/staging`
**Permission:** `app_staging` (manage) | **Throttle:** 5/min

Create a staging clone.

**Request:** `{"domain": "staging.example.com"}`

**Response `201`:** `{"staging": {"id": 5, "status": "provisioning", …}}`

---

### POST `/applications/{application}/staging/push`
**Permission:** `app_staging` (manage) | **Throttle:** 5/min

Push staging changes back to production.

**Request:** `{"mode": "full|partial"}`

- `full` — replace production files + database.
- `partial` — database only (safer).

**Response `200`:** `{"application": {...updated production record...}}`

---

## Application — AI Bot Blocker

### GET `/ai-bot-policies`
**Permission:** `app_bot_blocker` (view)

The three policy choices and exactly which bots each blocks (resolved server-side).

```json
{"ai_bot_policies": {
  "allow_all": {"title": "Allow all", "description": "…", "blocked_bots": [], "blocked_count": 0},
  "block_training": {"title": "Block AI training crawlers", "description": "…", "blocked_bots": ["ClaudeBot", "ChatGPT-User", …], "blocked_count": 14},
  "block_all": {"title": "Block all AI crawlers", "description": "…", "blocked_bots": ["ClaudeBot", "GPTBot", "ChatGPT-User", …], "blocked_count": 17}
}}
```

---

### PUT `/applications/{application}/bot-blocker`
**Permission:** `app_bot_blocker` (manage) | **Throttle:** 10/min

Set the policy and custom allow/block rules for specific user agents.

**Request:**
```json
{"policy": "block_training", "blocked": ["SomeBot/1.0"], "allowed": ["ClaudeBot/2.0"]}
```

**Response `200`:**
```json
{"application": {
  "id": 1, "ai_bot_policy": "block_training",
  "bot_rules": [{"rule": "ClaudeBot/2.0", "action": "allow"}, {"rule": "SomeBot/1.0", "action": "block"}]
}}
```

---

### GET `/applications/{application}/bot-traffic`
**Permission:** `app_log` (view) | **Throttle:** 30/min

Evidence for the policy decision: which bots hit this site recently, and whether the current settings block them.

**Query:** `?days=7` (default 7, max 30)

**Response `200`:**
```json
{"bot_traffic": {
  "period_days": 7, "total_requests": 4821,
  "bots": [
    {"user_agent": "ClaudeBot/2.0", "requests": 142, "blocked": true, "policy_result": "blocked"},
    {"user_agent": "Googlebot/2.1", "requests": 891, "blocked": false, "policy_result": "allowed"}
  ]
}}
```

Gated on `app_log` (not `app_bot_blocker`) because it reads the site's access log — prevents widening the bot-blocker permission into log access.

---

## Application — 8G WAF

### GET `/waf-options`
**Permission:** `app_firewall` (view)

The six WAF rule categories and two enforcement modes.

```json
{"waf_categories": [
  {"value": "bad_referers", "title": "Bad Referrers", "description": "Blocks empty referrer and known spam referrers."},
  {"value": "bad_bots", "title": "Bad Bots", "description": "Blocks known malicious crawlers and scrapers."},
  …
], "waf_modes": [
  {"value": "detect", "title": "Detect only"},
  {"value": "enforce", "title": "Enforce rules"}
]}
```

---

### GET `/applications/{application}/waf`
**Permission:** `app_firewall` (view)

Current WAF state for this application.

```json
{"application": {
  "id": 1, "waf_enabled": true, "waf_mode": "enforce",
  "waf_categories": {"bad_referers": true, "bad_bots": true, "sql_injection": true, "xss": true, "spam": false, "bad_js": false},
  "waf_exceptions": [{"kind": "user_agent", "value": "MyClient/1.0"}],
  "waf_custom_rules": []
}}
```

---

### PUT `/applications/{application}/waf`
**Permission:** `app_firewall` (manage) | **Throttle:** 10/min

Update WAF settings.

**Request:**
```json
{
  "enabled": true, "mode": "enforce",
  "categories": {"bad_referers": true, "bad_bots": true, "sql_injection": true, "xss": true, "spam": false, "bad_js": false},
  "exceptions": [{"kind": "user_agent", "value": "MyClient/1.0"}],
  "custom_rules": []
}
```

**Response `200`:** `{"application": {...updated...}}`

---

## Application — Per-site Fail2ban

Per-application fail2ban watches one site's own access log. The jail and filter are raw INI files written verbatim to `/etc/fail2ban/{jail,filter}.d/sVoss-<slug>.conf` and reloaded into the daemon — the same shape the commercial ServerAvatar API exposes. Any feature fail2ban's INI supports (custom regex, multiple logpaths, additional actions) is reachable from the form, not just the structured `maxretry/findtime/bantime` of the previous implementation.

On `GET`, applications with the old structured columns still on disk are migrated to the new INI form on the first read, after which the structured columns are dropped on the next migration. New applications start with `fail2ban: null` and the templates below.

### GET `/applications/{application}/fail2ban`
**Permission:** `app_fail2ban` (view)

Response when the application has never been configured — `fail2ban` is `null`, the two templates are the defaults the form can pre-fill with:

```json
{"fail2ban": null, "jail_template": "...default jail INI...", "filter_template": "...default filter INI..."}
```

Response when configured — `fail2ban` carries the saved values, and the templates echo them so the form renders the user's last submission unchanged until they edit it. `jail_template` and `filter_template` are always present alongside `fail2ban` so the form can pre-fill with the saved values (not defaults) without any client-side logic:

```json
{"fail2ban": {
  "jail_name": "sVoss-shop",
  "jail_content": "[sVoss-shop]\nenabled  = true\nport     = http,https\nfilter   = sVoss-shop\nlogpath  = /var/log/nginx/shop.access.log\nmaxretry = 3\nbantime  = 3600\nfindtime = 600\n",
  "filter_content": "[sVoss-shop]\nfailregex = ^<HOST> .* \"(POST|PUT|DELETE) .*wp-login.php\n           ^<HOST> .* \"(POST|PUT|DELETE) .*xmlrpc.php\n           ^<HOST> .* \"(POST|PUT|DELETE) .*wp-admin.*\nignoreregex =\n"
}, "jail_template": "[sVoss-shop]\nenabled  = true\nport     = http,https\n...", "filter_template": "[sVoss-shop]\nfailregex = ^<HOST> .* \"(POST|PUT|DELETE) .*wp-login.php\n..."}
```

The jail file template:

```ini
[sVoss-{slug}]
enabled  = true
port     = http,https
filter   = sVoss-{slug}
logpath  = {webroot}/logs/access.log
maxretry = 3
bantime  = 3600
findtime = 600
```

The filter file template:

```ini
[sVoss-{slug}]
failregex = ^<HOST> .* "(POST|PUT|DELETE) .*wp-login.php
           ^<HOST> .* "(POST|PUT|DELETE) .*xmlrpc.php
           ^<HOST> .* "(POST|PUT|DELETE) .*wp-admin.*
ignoreregex =
```

`{slug}`, `{name}`, `{filter}`, `{logpath}` are replaced with the resolved values when the file is written. Any other content the user submits is left untouched, so a custom regex or action is preserved end-to-end.

---

### POST `/applications/{application}/fail2ban`
**Permission:** `app_fail2ban` (manage) | **Throttle:** 10/min

Validate, dry-run against `fail2ban-client -t`, save, write to disk, reload. The dry-run is the gate: a config that does not parse against `fail2ban-client` never reaches the live daemon.

**Request:**
```json
{"jail_config_content": "[sVoss-shop]\nenabled  = true\n...\n", "filter_config_content": "[sVoss-shop]\nfailregex = ^<HOST>\n...\n"}
```

Both fields are required, must be strings, and are capped at 65,535 characters.

**Response `200`** — test passed, configuration applied:
```json
{"testOk": true, "message": "Fail2ban configured successfully!"}
```

**Response `500`** — test failed, nothing was saved or written to disk:
```json
{"testOk": false, "message": "Fail2ban configuration test failed.", "output": "ERROR: ..."}
```

---

### DELETE `/applications/{application}/fail2ban`
**Permission:** `app_fail2ban` (manage) | **Throttle:** 10/min

Remove the jail file from `/etc/fail2ban/jail.d/`, reload the daemon, and clear the saved content. The filter file is left in place — dropping it would invalidate every other jail that referenced the same filter, and there is no clean way to know whether the filter is shared with another application.

**Response `200`** — disabled:
```json
{"message": "Fail2ban disabled successfully!"}
```

**Response `500`** — already disabled (no saved content):
```json
{"message": "Fail2ban is already disabled for this application."}
```

---

## Application — Logs

### GET `/applications/{application}/logs`
**Permission:** `app_log` (view)

Which log sources this application has and whether each exists yet.

```json
{"logs": [
  {"key": "access", "label": "Access Log", "kind": "access", "exists": true, "size": 1048576, "modified": "29-07-2026 11:00:00"},
  {"key": "error", "label": "Error Log", "kind": "error", "exists": true, "size": 4096, "modified": "29-07-2026 10:55:00"},
  {"key": "supervisor", "label": "Worker Output", "kind": "process", "exists": false}
]}
```

---

### GET `/applications/{application}/logs/{key}`
**Permission:** `app_log` (view) | **Throttle:** 120/min

Read a log source. Supports cursor-based tailing.

**Query:** `?lines=200&grep=error` (lines: default 200, max 5000; grep: case-insensitive literal filter)

**Response `200`:**
```json
{"log": {
  "key": "access", "label": "Access Log", "kind": "access", "exists": true,
  "lines": ["192.168.1.1 - - [29/Jul/2026:11:00:00 +0000] \"GET / HTTP/1.1\" 200 1234"],
  "cursor": 1048576, "truncated": false
}}
```

`exists: false` — file not created yet (e.g. a never-visited site has no access log). `truncated: true` — content was capped at the line limit.

For live tail, poll with `?after=<cursor>` — returns only newly-appended lines.

---

## Application — Workers (Queue / Background Processes)

### GET `/applications/{application}/workers`
**Permission:** `app_worker` (view)

```json
{"workers": [
  {"id": 1, "name": "queue", "command": "php artisan queue:work --sleep=3 --tries=3", "directory": "/home/siteowner/shop.example.com", "numprocs": 2, "autostart": true, "autorestart": true, "startsecs": 3, "stopwaitsecs": 10, "enabled": true, "status": "running"}
], "presets": [
  {"name": "Queue Worker", "command": "php artisan queue:work --sleep=3 --tries=3", "directory": "{path}", "numprocs": 1, "autostart": true, "autorestart": true}
], "checks": {"queue_present": true}}
```

`presets` — one-click setups for common patterns (queue worker, Horizon, custom). `{path}` is substituted with the app's root directory.

---

### POST `/applications/{application}/workers`
**Permission:** `app_worker` (manage) | **Throttle:** 20/min

Add a worker.

**Request:**
```json
{"name": "queue", "command": "php artisan queue:work --sleep=3 --tries=3", "directory": "/home/siteowner/shop.example.com", "numprocs": 2, "autostart": true, "autorestart": true, "startsecs": 3, "stopwaitsecs": 10}
```

**Response `201`:** `{"worker": {...}}`

---

### PUT `/applications/{application}/workers/{worker}`
**Permission:** `app_worker` (manage) | **Throttle:** 20/min

Update worker settings. Changes write a new systemd unit and restart the worker.

**Request:** `{"numprocs": 4, "autostart": false}`

**Response `200`:** `{"worker": {...updated...}}`

---

### DELETE `/applications/{application}/workers/{worker}`
**Permission:** `app_worker` (manage) | **Throttle:** 20/min

Remove the worker (stops the process, removes the unit, deletes the record).

**Response `204`:** `null`

---

### POST `/applications/{application}/workers/{worker}/{action}`
**Permission:** `app_worker` (manage) | **Throttle:** 30/min

Control a running worker. `{action}` = `start | stop | restart`.

**Response `200`:** `{"worker": {"id": 1, "status": "running"}}`

---

## Application — Site Clone

### GET `/clones`
**Permission:** `app_clone` (view)

Every clone across every application, newest first. For resuming a clone from a different browser session than the one that started it.

```json
{"clones": [{"id": 1, "source_application_id": 5, "source_application_name": "shop", "target_application_id": 8, "name": "shop-backup", "domain": "backup.example.com", "status": "completed", "status_title": "Completed", "current_step": null, "reason": null, "started_at": "29-07-2026 10:00:00", "finished_at": "29-07-2026 10:03:00"}], "meta": {"current_page": 1, "per_page": 20, "total": 3, "last_page": 1}}
```

---

### POST `/applications/{application}/clone`
**Permission:** `app_clone` (manage) | **Throttle:** 5/min

Duplicate an application to a new domain. Runs async on the queue.

**Request:**
```json
{"name": "shop-backup", "domain": "backup.example.com", "system_user_id": 1, "site_user_password": "…"}
```

**Response `202`:**
```json
{"clone": {
  "id": 1, "name": "shop-backup", "domain": "backup.example.com",
  "status": "pending", "status_title": "Queued",
  "source_application": {"id": 1, "name": "shop"},
  "current_step": null, "step_number": null, "total_steps": 7,
  "started_at": "29-07-2026 10:00:00"
}}
```

---

### GET `/clones/{clone}`
**Permission:** `app_clone` (view) | **Throttle:** 120/min

Poll while running.

```json
{"clone": {
  "id": 1, "status": "running", "status_title": "Cloning…",
  "current_step": "Copying files", "step_number": 4, "total_steps": 7,
  "started_at": "29-07-2026 10:00:00", "finished_at": null
}}
```

On completion: `status: "completed", "finished_at: "29-07-2026 10:03:00"`.
On failure: `status: "failed", "status_title: "Clone failed", "reason": "…"`.

---

## Backups

### GET `/backup-targets`
**Permission:** `backup` (view)

Every application and its backup configuration — the overview screen.

```json
{"backup_targets": [
  {"id": 1, "application": {"id": 1, "name": "shop", "domain": "shop.example.com"}, "configured": true, "storage_destination": {"id": 1, "name": "S3 Backup"}, "schedule": "daily", "retention": 7, "last_backup": {"id": 15, "status": "completed", "created_at": "28-07-2026 02:00:00"}},
  {"id": null, "application": {"id": 2, "name": "blog", "domain": "blog.example.com"}, "configured": false, "storage_destination": null, "schedule": null, "retention": null, "last_backup": null}
], "meta": {"total": 7, "protected": 4, "unprotected": 3}}
```

---

### GET `/applications/{application}/backup-target`
**Permission:** `app_backup` (view)

Backup settings for one application.

```json
{"backup_target": {
  "id": 1, "storage_destination_id": 1,
  "storage_destination": {"id": 1, "name": "S3 Backup", "provider": "s3", "bucket": "my-backups"},
  "schedule": "daily", "retention": 7, "enabled": true
}}
```

`backup_target: null` — not configured.

---

### PUT `/applications/{application}/backup-target`
**Permission:** `app_backup` (manage)

Configure or update backup settings.

**Request:**
```json
{"storage_destination_id": 1, "schedule": "daily", "retention": 7, "enabled": true}
```

**Response `200`:** `{"backup_target": {...}}`

---

### POST `/applications/{application}/backups`
**Permission:** `app_backup` (manage) | **Throttle:** 6/min

Run a backup immediately.

**Response `202`:** `{"backup_target": {"id": 1, "schedule": "daily", …}}`

Poll the backup's own `GET /backups/{id}` while it runs.

`422` if not configured or if a backup is already in progress.

---

### GET `/backups`
**Permission:** `backup` (view)

Every backup across every application — paginated, filterable.

**Query:** `?filter[application_id]=1&filter[status]=completed&filter[type]=full&filter[from]=2026-07-01&filter[to]=2026-07-31&page=1&per_page=20`

```json
{"backups": [{
  "id": 15, "application_id": 1,
  "application": {"id": 1, "name": "shop", "domain": "shop.example.com"},
  "type": "full", "status": "completed", "status_title": "Completed",
  "size_bytes": 52428800, "size_human": "50 MB",
  "is_safety": false,
  "created_at": "28-07-2026 02:00:00", "created_at_human": "Yesterday"
}], "meta": {"current_page": 1, "per_page": 20, "total": 45, "last_page": 3, "counts": {"total": 45, "pending": 0, "running": 1, "completed": 40, "failed": 4}}}
```

---

### GET `/backups/{backup}`
**Permission:** `backup` (view)

Poll one backup.

```json
{"backup": {
  "id": 15, "application_id": 1, "type": "full",
  "status": "completed", "status_title": "Completed",
  "size_bytes": 52428800, "size_human": "50 MB",
  "is_safety": false,
  "created_at": "28-07-2026 02:00:00", "created_at_human": "Yesterday",
  "finished_at": "28-07-2026 02:04:00", "finished_at_human": "4 minutes later"
}}
```

---

### GET `/backups/{backup}/download`
**Permission:** `backup` (manage) | **Throttle:** 6/min

Get a time-limited presigned download URL for the archive.

```json
{"download": {
  "url": "https://s3.amazonaws.com/bucket/abc123?X-Amz-Signature=…",
  "expires_at": "29-07-2026 10:05:00",
  "filename": "shop-example-com-2026-07-28-020000-full.tar.gz",
  "size_bytes": 52428800
}}
```

`url` expires 5 minutes after issuance. `filename` is human-readable (`domain-YYYY-MM-DD-HHMMSS-type.tar.gz`).

---

### POST `/backups/{backup}/retry`
**Permission:** `app_backup` (manage) | **Throttle:** 6/min

Re-run a failed backup with the same configuration.

**Response `202`:** `{"backup_target": {"id": 1, …}}`

`422` if the backup did not fail or if a backup is already in progress.

---

## Backup — Restores

### GET `/restores`
**Permission:** `backup` (view)

Restore history — what was restored, when, and by whom. Paginated.

**Query:** `?filter[application_id]=1&filter[status]=succeeded&page=1`

```json
{"restores": [{
  "id": 3, "backup_id": 14, "application_id": 1,
  "application": {"id": 1, "name": "shop", "domain": "shop.example.com"},
  "type": "full", "status": "succeeded", "status_title": "Restore succeeded",
  "reason": null, "current_step": null,
  "started_at": "28-07-2026 10:00:00", "finished_at": "28-07-2026 10:05:00",
  "safety_backup_id": 16, "rollback_path": "/home/siteowner/.rollback-3"
}], "meta": {"current_page": 1, "per_page": 20, "total": 3, "last_page": 1}}
```

`safety_backup_id` — the pre-restore snapshot created automatically. `rollback_path` — the previous site directory still on disk.

---

### GET `/restores/{restore}`
**Permission:** `backup` (view) | **Throttle:** 120/min

Poll a running restore.

```json
{"restore": {
  "id": 3, "status": "running", "status_title": "Restoring…",
  "reason": null,
  "current_step": "Restoring files", "step_number": 4, "total_steps": 7,
  "started_at": "28-07-2026 10:00:00", "finished_at": null
}}
```

On success: `status: "succeeded"`, `safety_backup_id`, `rollback_path`.
On failure: `status: "failed"`, `reason` (step name), `reason_title` (localised explanation of whether anything changed).

---

### POST `/backups/{backup}/restore`
**Permission:** `backup` (manage) | **Throttle:** 2/min

Restore an application from this backup. **Destructive** — overwrites live data.

**Request:**
```json
{"type": "full", "confirm": "shop.example.com"}
```

`confirm` must match the application's domain exactly (string comparison). `type` = `full | files | database`.

**Response `202`:** `{"restore": {"id": 3, "status": "pending", "confirm": "shop.example.com", …}}`

Poll `GET /restores/{id}` until `status` is `succeeded` or `failed`.

---

## Databases

### GET `/databases/engines`
**Permission:** `database` (view)

Capability list for all three engines.

```json
{"engines": [{
  "engine": "mysql", "driver": "mysql", "running": false, "version": null,
  "installed": false, "installable": true,
  "install_status": null, "install_reason": null, "install_message": null
}, {
  "engine": "mariadb", "driver": "mysql", "running": true, "version": "10.11.4",
  "installed": true, "installable": true,
  "install_status": null, "install_reason": null, "install_message": null
}, {
  "engine": "mongodb", "driver": "mongodb", "running": false, "version": null,
  "installed": false, "installable": false,
  "install_status": null, "install_reason": null, "install_message": null
}]}
```

`install_status` is only ever `installing | failed | null` — never `installed`. A finished install removes its row.

---

### POST `/databases/engines/{engine}`
**Permission:** `database` (manage)

Install MySQL or MariaDB. MongoDB is not installable via the panel.

**Response `202`:** `{"queued": true}` — poll `GET /databases/engines`.

Already installed → `200` with the engine list.

---

### GET `/databases/connections`
**Permission:** `database` (view)

```json
{"connections": [{
  "engine": "mariadb", "driver": "mysql",
  "connection_type": "socket", "socket": "/var/run/mysqld/mysqld.sock",
  "username": "panel_abc123xyz",
  "has_password": true, "options": {}
}]}
```

Passwords are never returned.

---

### PUT `/databases/connections/{engine}`
**Permission:** `database` (manage)

Update the admin connection config.

**Request (TCP):** `{"connection_type": "tcp", "host": "127.0.0.1", "port": 3306, "username": "panel_abc123xyz", "password": "…"}`

**Request (Socket):** `{"connection_type": "socket", "socket": "/var/run/mysqld/mysqld.sock", "username": "panel_abc123xyz", "password": "…"}`

**Response `200`:** `{"connections": [...], "mariadb": {"reachable": true}}`

---

### POST `/databases/connections/{engine}/test`
**Permission:** `database` (manage)

Test reachability of the admin connection.

**Response `200`:** `{"reachable": true}`

---

### GET `/databases`
**Permission:** `database` (view)

```json
{"databases": [{
  "id": 1, "name": "shop_db", "engine": "mariadb", "driver": "mysql",
  "charset": "utf8mb4", "collation": "utf8mb4_unicode_ci",
  "application_id": 1,
  "size_bytes": 2097152, "size_human": "2 MB",
  "users_count": 1,
  "created_at": "25-07-2026 14:30:00", "created_at_human": "2 weeks ago"
}]}
```

`size_bytes` is stored and refreshed every 10 minutes by a scheduled command.

---

### POST `/databases`
**Permission:** `database` (manage)

**Request:**
```json
{"name": "shop_db", "engine": "mariadb", "charset": "utf8mb4", "collation": "utf8mb4_unicode_ci", "application_id": 1}
```

**Response `201`:** `{"database": {…}}`

---

### GET `/databases/{database}`
**Permission:** `database` (view)

Size is re-measured on this single-record view (exact figure worth one query).

```json
{"database": {"id": 1, "name": "shop_db", …, "users": [{"id": 1, "username": "shopuser"}]}}
```

---

### DELETE `/databases/{database}`
**Permission:** `database` (manage)

Drops the database and cascades its users. No orphans.

**Response `204`:**

---

### GET `/databases/untracked`
**Permission:** `database` (view)

Server databases not yet under panel management (brownfield discovery).

**Query:** `?engine=mariadb`

**Response `200`:** `{"untracked": ["legacy_app_db", "old_cms"]}`

---

### POST `/databases/adopt`
**Permission:** `database` (manage)

Bring existing server databases under panel management.

**Request:** `{"engine": "mariadb", "names": ["legacy_app_db"]}`

**Response `201`:** `{"databases": [{"id": 5, "name": "legacy_app_db", …}]}`

---

### GET `/databases/{database}/tables`
**Permission:** `database` (view)

Structure only — no data browsing.

```json
{"tables": [
  {"name": "wp_posts", "rows": 1420, "size_bytes": 524288},
  {"name": "wp_users", "rows": 8, "size_bytes": 8192}
]}
```

---

### POST `/databases/{database}/optimize`
**Permission:** `database` (manage)

Run `OPTIMIZE TABLE` across all tables.

**Response `200`:** `{"database": {…}}`

---

### POST `/databases/{database}/repair`
**Permission:** `database` (manage)

Run `REPAIR TABLE` across all tables.

**Response `200`:** `{"database": {…}}`

---

### POST `/databases/{database}/export`
**Permission:** `database` (manage)

Dump a database to a file. Queued.

**Response `202`:** `{"export": {"id": 1, "status": "queued", "file": null}}`

Poll `GET /databases/exports`.

---

### GET `/databases/exports`
**Permission:** `database` (view)

All exports, newest first. Includes in-flight rows.

```json
{"exports": [{
  "id": 1, "database_id": 1, "database_name": "shop_db", "engine": "mariadb",
  "status": "completed", "file": "shop_db-2026-07-28-100000.sql.gz",
  "size_bytes": 2097152, "size_human": "2 MB",
  "available": true,
  "created_at": "28-07-2026 10:00:00", "created_at_human": "Yesterday"
}]}
```

---

### GET `/databases/exports/{file}`
**Permission:** `database` (view)

Stream a previously-created export for download. Filename is strictly validated (alphanumeric + `.` `-` `_` only).

**Response:** Binary file stream.

---

### DELETE `/databases/exports/{export}`
**Permission:** `database` (manage)

Delete the export row **and** its file.

**Response `204`:**

---

### GET `/databases/{database}/users`
**Permission:** `database` (view)

```json
{"users": [{
  "id": 1, "database_id": 1, "username": "shopuser",
  "connection_preference": "localhost",
  "host": null, "connection_string": "shopuser@localhost",
  "created_at": "25-07-2026 14:30:00", "created_at_human": "2 weeks ago"
}]}
```

---

### POST `/databases/{database}/users`
**Permission:** `database` (manage)

**Request:** `{"username": "shopuser2", "password": "…", "connection_preference": "localhost"}`

`connection_preference`: `localhost | remote | anywhere`. Remote/anywhere opens the engine port in the firewall.

**Response `201`:** `{"user": {...}}`

---

### PATCH `/databases/{database}/users/{user}`
**Permission:** `database` (manage)

Update username, connection preference, or password.

**Request:** `{"connection_preference": "anywhere", "host": "0.0.0.0/0"}`

**Response `200`:** `{"user": {...}}`

---

### PUT `/databases/{database}/users/{user}/password`
**Permission:** `database` (manage)

**Request:** `{"password": "newpassword"}`

**Response `200`:** `{"user": {...}}`

---

### DELETE `/databases/{database}/users/{user}`
**Permission:** `database` (manage)

**Response `204`:**

---

### POST `/databases/{database}/phpmyadmin-sso`
**Permission:** `database` (view)

One-click auto-login to phpMyAdmin for the database's user. Works only for MySQL/MariaDB databases (MongoDB is not supported by phpMyAdmin — see `mongo-express` instead). Requires a running phpMyAdmin site on this server.

The frontend receives a `redirect_url` and should immediately redirect the browser to it. The URL contains a one-time token (TTL 60 s) that the `sso.php` shim on the PMA site consumes atomically.

**Query (optional):** `?database_user_id=1` — log in as a specific database user. Without this the first available user is used.

**Response `200`:**
```json
{"redirect_url": "https://pma.example.com/sso.php?token=***"}
```

**Response `422`** — MongoDB database:
```json
{"message": "phpMyAdmin does not support MongoDB databases."}
```

**Response `422`** — no phpMyAdmin site deployed:
```json
{"message": "No phpMyAdmin site is installed on this server."}
```

**Response `422`** — no database user exists for this database:
```json
{"message": "Create a database user before accessing phpMyAdmin."}
```

---

### GET `/databases/processes`
**Permission:** `database` (view)

Live process list for the active SQL engine.

**Query:** `?engine=mariadb`

```json
{"processes": [{
  "id": 42, "user": "shopuser", "host": "localhost",
  "db": "shop_db", "command": "Sleep", "time": 5,
  "state": "", "query": null
}]}
```

---

### DELETE `/databases/processes/{id}`
**Permission:** `database` (manage)

Kill a process/op (`KILL`).

**Query:** `?engine=mariadb`

**Response `204`:**

---

### GET `/databases/status/{engine}`
**Permission:** `database` (view)

```json
{"status": {
  "connections": 5, "max_connections": 100,
  "threads_running": 2, "queries": 12847, "slow_queries": 3,
  "uptime_seconds": 864000
}}
```

---

### GET `/databases/metrics/history`
**Permission:** `database` (view)

24h QPS + connection history for the **Setup page / Database Metrics** chart.

**Query:** `?engine=mariadb`

```json
{"metrics": [
  {"sampled_at": "28-07-2026 00:00:00", "qps": 12.4, "connections": 3, "threads_running": 1},
  …
]}
```

---

## System Users

### GET `/system-users`
**Permission:** `system_user` (view)

```json
{"system_users": [{
  "id": 1, "username": "siteowner",
  "home_path": "/home/siteowner",
  "shell": "/bin/bash",
  "sudo_access": false, "ssh_access": true,
  "applications_count": 3,
  "created_at": "23-07-2026 10:00:00", "created_at_human": "3 weeks ago"
}]}
```

---

### POST `/system-users`
**Permission:** `system_user` (manage)

**Request:** `{"username": "newuser", "password": "…", "shell": "/bin/bash"}`

**Response `201`:** `{"system_user": {...}}`

---

### GET `/system-users/{systemUser}`
**Permission:** `system_user` (view)

```json
{"system_user": {
  "id": 1, "username": "siteowner", "home_path": "/home/siteowner",
  "shell": "/bin/bash", "sudo_access": false, "ssh_access": true,
  "applications": [{"id": 1, "name": "shop"}, {"id": 2, "name": "blog"}],
  "created_at": "23-07-2026 10:00:00"
}}
```

---

### DELETE `/system-users/{systemUser}`
**Permission:** `system_user` (manage)

`422` if the user owns any applications.

**Response `204`:**

---

### PUT `/system-users/{systemUser}/password`
**Permission:** `system_user` (manage)

**Request:** `{"password": "newpassword"}`

**Response `200`:** `{"message": "Password updated."}`

---

### PUT `/system-users/{systemUser}/sudo`
**Permission:** `system_user` (manage)

**Request:** `{"enabled": true}`

**Response `200`:** `{"system_user": {"id": 1, "sudo_access": true}}`

---

### PUT `/system-users/{systemUser}/shell`
**Permission:** `system_user` (manage)

**Request:** `{"shell": "/usr/sbin/nologin"}`

**Response `200`:** `{"system_user": {"id": 1, "shell": "/usr/sbin/nologin"}}`

---

### PUT `/system-users/{systemUser}/ssh`
**Permission:** `system_user` (manage)

Enable/disable SSH login for this system user.

**Request:** `{"enabled": false}`

**Response `200`:** `{"system_user": {"id": 1, "ssh_access": false}}`

---

### GET `/system-users/{systemUser}/ssh-keys`
**Permission:** `system_user` (view)

```json
{"ssh_keys": [{"id": 1, "name": "MacBook Pro", "fingerprint": "SHA256:abc123…", "created_at": "23-07-2026 10:05:00"}]}
```

---

### POST `/system-users/{systemUser}/ssh-keys`
**Permission:** `system_user` (manage)

**Request:** `{"name": "MacBook Pro", "public_key": "ssh-rsa AAAA…"}`

**Response `201`:** `{"ssh_key": {"id": 1, "name": "MacBook Pro", "fingerprint": "SHA256:abc123…"}}`

---

### DELETE `/system-users/{systemUser}/ssh-keys/{sshKey}`
**Permission:** `system_user` (manage)

**Response `204`:**

---

## Cronjobs

### GET `/cronjobs/schedule-presets`
**Permission:** `cronjob` (view)

Dropdown options for schedule expressions.

```json
{"presets": {"frequencies": [{"value": "hourly", "label": "Hourly"}, …], "minutes": [{"value": 0, "label": ":00"}, …], "hours": [{"value": 0, "label": "00:00"}, …], "days_of_month": [{"value": 1, "label": "1st"}, …], "days_of_week": [{"value": 0, "label": "Sunday"}, …]}}
```

---

### GET `/cronjobs/command-presets`
**Permission:** `cronjob` (view)

Framework shortcuts for the command field.

```json
{"presets": [
  {"label": "Laravel Scheduler", "command": "* * * * * cd {path} && php artisan schedule:run >> /dev/null 2>&1"},
  {"label": "WP Cron", "command": "* * * * * cd {path} && wp cron event run --due-now >> /dev/null 2>&1"}
], "placeholder": "{path}"}
```

`{path}` is substituted with the application's root directory before saving.

---

### GET `/cronjobs`
**Permission:** `cronjob` (view) | **Throttle:** API

Paginated, filterable. Filters: `filter[system_user_id]`, `filter[application_id]`, `filter[username]`, `filter[active]`.

```json
{"cronjobs": [{
  "id": 1, "user": "siteowner", "system_user": {"id": 1, "username": "siteowner"},
  "command": "cd /home/siteowner/shop.example.com && php artisan schedule:run",
  "expression": "*/5 * * * *", "human": "Every 5 minutes",
  "active": true, "last_run_at": "29-07-2026 09:55:00", "next_run_at": "29-07-2026 10:00:00",
  "created_at": "25-07-2026 14:30:00"
}], "meta": {"current_page": 1, "per_page": 10, "total": 3, "last_page": 1}}
```

---

### POST `/cronjobs`
**Permission:** `cronjob` (manage)

**Request:**
```json
{"user_id": 1, "application_id": null, "command": "cd /home/siteowner/shop.example.com && php artisan schedule:run", "expression": "*/5 * * * *", "active": true}
```

`application_id` optional — if set, the cron runs as the app's system user; otherwise uses `user_id`.

**Response `201`:** `{"cronjob": {...}}`

---

### GET `/cronjobs/{cronjob}`
**Permission:** `cronjob` (view)

```json
{"cronjob": {"id": 1, "user_id": 1, "system_user": {"id": 1, "username": "siteowner"}, …}}
```

---

### PUT `/cronjobs/{cronjob}`
**Permission:** `cronjob` (manage)

**Request:** `{"expression": "*/10 * * * *", "active": false}`

**Response `200`:** `{"cronjob": {...}}`

---

### DELETE `/cronjobs/{cronjob}`
**Permission:** `cronjob` (manage)

Removes the `/etc/cron.d` file entry.

**Response `204`:**

---

## Server — Dashboard

### GET `/server/facts`
**Permission:** `dashboard` (view)

```json
{"facts": {
  "hostname": "srv1", "os": "Ubuntu 24.04 LTS", "kernel": "6.8.0-36-generic",
  "arch": "x86_64", "uptime": {"seconds": 1296000, "human": "15 days"},
  "ip": "167.233.229.184",
  "cpu": {"model": "AMD EPYC 7282", "cores": 8},
  "memory_total": 8589934592, "memory_total_human": "8 GB",
  "disk_total": 107374182400, "disk_total_human": "100 GB",
  "timezone": "UTC", "reboot_required": false,
  "runtimes": {"php": "8.4", "node": "20.11.0", "nginx": "1.24.0", "redis": "7.2.0", "mysql": null}
}}
```

---

### GET `/server/metrics/live`
**Permission:** `dashboard` (view)

Current snapshot — poll every 2–5s for live gauges. **Network and disk I/O are rates between two polls** — the first poll returns 0.

```json
{"metrics": {
  "cpu": {"percent": 12.5, "cores": 8},
  "memory": {"total": 8589934592, "used": 4294967296, "free": 4294967296, "percent": 50, "total_human": "8 GB", "used_human": "4 GB", "free_human": "4 GB"},
  "swap": {"total": 2147483648, "used": 0, "free": 2147483648, "percent": 0, "total_human": "2 GB", "used_human": "0 B", "free_human": "2 GB"},
  "disk": {"total": 107374182400, "used": 64424509440, "free": 42949672960, "percent": 60, "total_human": "100 GB", "used_human": "60 GB", "free_human": "40 GB"},
  "load": {"1": 0.5, "5": 1.2, "15": 2.0},
  "network": {"in": 10240, "out": 5120, "in_human": "10 KB/s", "out_human": "5 KB/s"},
  "disk_io": {"read": 2097152, "write": 524288, "read_human": "2 MB/s", "write_human": "512 KB/s", "read_ops": 45, "write_ops": 12}
}}
```

`cpu_percent` is null on the first read (needs two samples). Show `—` for one tick.

---

### GET `/server/metrics/history`
**Permission:** `dashboard` (view)

24h series for the charts (5-min cadence).

```json
{"metrics": [
  {"sampled_at": "28-07-2026 00:00:00", "cpu": 8.2, "memory": 45.1, "swap": 0, "disk": 58, "load_1": 0.3, "net_in": 5120, "net_out": 2048, "disk_read": 0, "disk_write": 0},
  …
]}
```

---

### GET `/server/processes`
**Permission:** `dashboard` (view)

Top processes by CPU.

```json
{"processes": [
  {"pid": 1234, "user": "www-data", "cpu": 25.3, "memory": 4.2, "command": "php-fpm: pool www"},
  {"pid": 5678, "user": "siteowner", "cpu": 8.1, "memory": 1.5, "command": "node /home/siteowner/shop.example.com/server.js"}
]}
```

---

### DELETE `/server/processes/{pid}`
**Permission:** `dashboard` (manage)

Stop a process.

**Request (optional):** `{"signal": "KILL"}` — default is `TERM`.

**Response `200`:** `{"process": {"pid": 1234, "command": "php-fpm: pool www", "user": "www-data", "signal": "TERM"}}`

`404` — PID no longer running. `422` — PID 1, kernel threads, the panel's PHP, or protected service processes. `500` — signal failed.

---

## Services

### GET `/services`
**Permission:** `service` (view)

Live status of every managed systemd service.

```json
{"services": [{
  "key": "nginx", "label": "Nginx", "unit": "nginx.service",
  "status": "active", "enabled": true, "protected": true,
  "actions": ["start", "stop", "restart", "reload"],
  "testable": true,
  "usage": {"memory_bytes": 5242880, "memory_human": "5 MB", "memory_percent": 0.06, "cpu_percent": null, "tasks": 4}
}]}
```

`protected: true` — cannot be stopped or disabled (nginx, php-fpm, the panel's own services).

`actions` — rendered buttons directly from this array. `testable: true` — shows a **Test configuration** button.

`usage` is `null` for stopped services or when systemd accounting is off. `cpu_percent` is null on first read (cumulative counter needs two samples).

---

### POST `/services/{service}/config-test`
**Permission:** `service` (view)

Validate the service's configuration. Read-only — never reloads.

**Response `200`:**
```json
{"config_test": {"ok": true, "output": "nginx: the configuration file /etc/nginx/nginx.conf syntax is ok\nnginx: configuration file /etc/nginx/nginx.conf test is successful"}}
```

```json
{"config_test": {"ok": false, "output": "nginx: [emerg] unknown directive 'invald_directive'\n"}}
```

---

### PUT `/services/{service}`
**Permission:** `service` (manage)

Control a service. `{service}` = the service `key`.

**Request:** `{"action": "start | stop | restart | reload | enable | disable"}`

**Response `200`:** `{<service>: <refreshed service object>}`

`422` if the action is blocked for a protected service (`stop`/`disable`). `404` if the service key is unknown.

---

## Firewall

### GET `/firewall/presets`
**Permission:** `firewall` (view)

Inbound rule presets for the UI.

```json
{"presets": [
  {"label": "SSH", "port": 22, "protocol": "tcp"},
  {"label": "HTTP", "port": 80, "protocol": "tcp"},
  {"label": "HTTPS", "port": 443, "protocol": "tcp"},
  {"label": "MySQL", "port": 3306, "protocol": "tcp"}
]}
```

---

### GET `/firewall`
**Permission:** `firewall` (view)

```json
{"firewall": {
  "enabled": true,
  "rules": [
    {"id": 1, "type": "allow", "port": 22, "protocol": "tcp", "address": "203.0.113.5/32", "label": "Office IP", "created_at": "23-07-2026 10:00:00"}
  ],
  "risky_ports": [{"port": 3306, "service": "MySQL", "reason": "Database server"}, …]
}}
```

`risky_ports` — ports detected from installed database engines + config, to warn before opening them.

---

### POST `/firewall/rules`
**Permission:** `firewall` (manage)

Add a rule.

**Request:** `{"type": "allow", "port": 22, "protocol": "tcp", "address": "203.0.113.5/32"}`

**Response `201`:** `{"firewall_rule": {"id": 2, "type": "allow", "port": 22, "protocol": "tcp", "address": "203.0.113.5/32"}}`

---

### PUT `/firewall/rules/{firewallRule}`
**Permission:** `firewall` (manage)

**Request:** `{"address": "203.0.113.10/32"}`

**Response `200`:** `{"firewall_rule": {...}}`

---

### DELETE `/firewall/rules/{firewallRule}`
**Permission:** `firewall` (manage)

**Response `204`:**

---

### PUT `/firewall/toggle`
**Permission:** `firewall` (manage)

Enable or disable the firewall entirely.

**Request:** `{"enabled": false}`

**Response `200`:** `{"firewall": {"enabled": false, …}}`

---

## Fail2ban

### GET `/fail2ban`
**Permission:** `fail2ban` (view)

```json
{"fail2ban": {
  "installed": true, "running": true, "version": "0.11.2",
  "jails": [{
    "name": "sshd", "enabled": true, "status": "active",
    "total_bans": 12, "current_bans": 0,
    "ignore_policies": ["127.0.0.1", "::1"],
    "actions": ["iptables"],
    "lockout_risk": "low"
  }]
}}
```

`lockout_risk: "low | medium | high"` — warns when enabling a jail that could lock out the caller.

---

### POST `/fail2ban/install`
**Permission:** `fail2ban` (manage)

Install fail2ban if not present.

**Response `202`:** `{"message": "Installing Fail2ban."}`

---

### PUT `/fail2ban`
**Permission:** `fail2ban` (manage)

Update jail settings.

**Request:** `{"jails": [{"name": "sshd", "enabled": true, "max_retry": 5, "find_time": 600, "bantime": 3600}]}`

**Response `200`:** `{"fail2ban": {...}}`

---

### POST `/fail2ban/bans`
**Permission:** `fail2ban` (manage)

Ban an IP manually.

**Request:** `{"ip": "203.0.113.50", "jail": "sshd"}`

**Response `200`:** `{"banned": {"ip": "203.0.113.50", "jail": "sshd"}}`

---

### DELETE `/fail2ban/bans/{ip}`
**Permission:** `fail2ban` (manage)

Unban an IP from all jails.

**Response `200`:** `{"unbanned": {"ip": "203.0.113.50", "jails": ["sshd"]}}`

---

### DELETE `/fail2ban/bans`
**Permission:** `fail2ban` (manage)

Unban every address from every jail.

**Response `200`:** `{"unbanned": {"ips": ["203.0.113.50", "203.0.113.51"]}}`

---

## Server — Activity Log

### GET `/server/activity-log`
**Permission:** `activity_log` (view)

Server-level events only: cronjob, disk_cleaner, service, fail2ban, firewall, git_account, node, setting, panel_update. Per-app events (application, database, backup) are surfaced through their own feature. Separate from `access-admin` which gates the admin-wide log.

**Query:** `?filter[type]=cronjob&filter[action]=created&search=something&page=1&per_page=20`

```json
{"activity_log": [{"id": 1, "type": "cronjob", "action": "created", "description": "Cronjob created", "user": {"id": 1, "username": "admin"}, "properties": {}, "created_at": "29-07-2026 10:00:00", "created_at_human": "3 hours ago"}], "meta": {"current_page": 1, "per_page": 20, "total": 45, "last_page": 3}}
```

---

## Logs (Server-wide)

### GET `/logs`
**Permission:** `logs` (view)

All available log sources on the server.

```json
{"logs": [{
  "key": "nginx_error", "label": "Nginx Error Log", "group": "web",
  "size": 4096, "modified": "29-07-2026 11:00:00", "readable": true
}]}
```

`group`: `web | database | php | system | security | daemon`.

`readable: false` — file exists but panel can't read it (needs elevated access). Disable the open action.

---

### GET `/logs/{key}`
**Permission:** `logs` (view)

**Query:** `?lines=200&grep=error&after=1048576`

**Response `200`:**
```json
{"log": {
  "key": "nginx_error", "label": "Nginx Error Log", "group": "web",
  "lines": ["2026/07/29 11:00:00 [error] 1234#0: *1 connect() failed", …],
  "cursor": 1048576, "truncated": false
}}
```

For live tail: poll with `?after=<cursor>`.

---

### GET `/logs/{key}/download`
**Permission:** `logs` (view)

Stream the full file as a download (`Content-Disposition: attachment`).

---

## Disk Cleaner

### GET `/disk-cleaner`
**Permission:** `disk_cleaner` (view)

```json
{"disk": {"path": "/", "total": 107374182400, "used": 64424509440, "free": 42949672960, "percent": 60, "total_human": "100 GB", "used_human": "60 GB", "free_human": "40 GB"}, "categories": [{
  "key": "apt_cache", "label": "APT Cache", "description": "Cached apt packages", "group": "package", "method": "command",
  "paths": ["Cached apt packages"], "safe": true, "available": true, "reclaimable": 104857600, "reclaimable_human": "100 MB"
}]}
```

`method`: `delete | truncate | command`. `note` (localised) explains what each category does and what it keeps.

---

### POST `/disk-cleaner/clean`
**Permission:** `disk_cleaner` (manage)

**Request:** `{"categories": ["apt_cache", "journal"]}`

**Response `200`:** `{"disk": {…refreshed…}, "cleaned": [{"key": "apt_cache", "freed": 104857600, "freed_human": "100 MB"}], "freed_total": 524288000, "freed_total_human": "500 MB"}`

---

### GET `/disk-cleaner/schedule`
**Permission:** `disk_cleaner` (view)

```json
{"schedule": {"enabled": true, "frequency": "daily", "categories": ["apt_cache", "journal"], "threshold_percent": 80, "last_run_at": "27-07-2026 03:00:00", "last_run_at_human": "2 days ago"}}
```

`null` if no schedule is set.

---

### PUT `/disk-cleaner/schedule`
**Permission:** `disk_cleaner` (manage)

**Request:** `{"enabled": true, "frequency": "daily", "categories": ["apt_cache"], "threshold_percent": 80}`

**Response `200`:** `{"schedule": {...}}`

---

### DELETE `/disk-cleaner/schedule`
**Permission:** `disk_cleaner` (manage)

Remove the schedule entirely.

**Response `204`:**

---

### GET `/disk-cleaner/runs`
**Permission:** `disk_cleaner` (view)

Manual + scheduled run history, paginated.

```json
{"runs": [{"id": 1, "trigger": "scheduled", "categories": ["apt_cache"], "freed_total": 104857600, "freed_total_human": "100 MB", "status": "success", "created_at": "27-07-2026 03:00:00"}], "meta": {"current_page": 1, "per_page": 20, "total": 5, "last_page": 1}}
```

---

## Settings

### GET `/settings`
**Permission:** `setting` (view)

```json
{"settings": {
  "general": {"timezone": "UTC", "ntp": true, "clock_synchronized": true, "hostname": "srv1"},
  "swap": {"enabled": true, "path": "/swapfile", "size": 2147483648, "size_human": "2 GB", "used": 0, "used_human": "0 B", "free": 2147483648, "free_human": "2 GB"},
  "security": {"port": 22, "permit_root_login": "prohibit-password", "password_authentication": false, "has_ssh_key": true},
  "updates": {"security_updates_enabled": true, "auto_reboot": false, "reboot_time": "06:00", "reboot_required": false, "updates_available": 3, "security_updates_available": 1, "lists_refreshed_at": "29-07-2026 04:00:00", "unattended_last_run_at": "27-07-2026 06:18:00", "unattended_last_result": "success"},
  "redis": {"maxmemory": "256mb", "maxmemory_policy": "allkeys-lru", "has_password": true, "password_manageable": true, "running": true, "memory_used": 8388608, "memory_used_human": "8 MB"}
}, "last_changed": {"security": {"user": {"id": 1, "username": "admin"}, "at": "27-07-2026 10:00:00"}}}
```

`redis` group is omitted if Redis is not installed.

`null` for unavailable facts (e.g. `updates_available: null` when the check failed) — render differently from `0` (which means "nothing waiting").

---

### PUT `/settings/general`
**Permission:** `setting` (manage)

**Request:** `{"timezone": "Europe/London", "hostname": "newsrv", "ntp": true}`

**Response `200`:** `{"general": {...}}`

---

### PUT `/settings/swap`
**Permission:** `setting` (manage)

**Request:** `{"size_mb": 4096}` — `0` disables.

**Response `200`:** `{"swap": {...}}`

---

### POST `/settings/reboot`
**Permission:** `setting` (manage)

Schedule a server reboot.

**Request:** `{"delay_minutes": 5}` — `0` = now.

**Response `202`:** `{"reboot": {"scheduled": true, "when": "+5"}}`

---

### PUT `/settings/security`
**Permission:** `setting` (manage)

**Request:** `{"port": 22022, "permit_root_login": "no", "password_authentication": false}`

`422` if disabling password auth with no SSH key present (lockout guard).

**Response `200`:** `{"security": {...}}`

---

### PUT `/settings/updates`
**Permission:** `setting` (manage)

**Request:** `{"security_updates_enabled": true, "auto_reboot": true, "reboot_time": "06:00"}`

**Response `200`:** `{"updates": {...}}`

---

### PUT `/settings/redis`
**Permission:** `setting` (manage)

**Request:** `{"maxmemory": "512mb", "maxmemory_policy": "allkeys-lru", "password": "newpassword"}`

Omit `password` to leave it unchanged. `{"remove_password": true}` clears it.

**Response `200`:** settings applied. `{"message": "Password is being changed.", "reference": "…"}` + `202` when a password change is in progress (applied after response).

---

### GET `/settings/reboot-schedule/presets`
**Permission:** `setting` (view)

```json
{"frequencies": [{"value": "daily", "label": "Daily"}, …], "hours": [{"value": 0, "label": "00:00"}, …], "days_of_week": [{"value": 0, "label": "Sunday"}, …]}
```

---

### PUT `/settings/reboot-schedule`
**Permission:** `setting` (manage)

**Request:** `{"enabled": true, "frequency": "weekly", "hour": 6, "day_of_week": 1}`

`day_of_month` (1–28) for monthly. `day_of_month` caps at 28.

**Response `200`:** `{"reboot_schedule": {"enabled": true, "frequency": "weekly", "hour": 6, "day_of_week": 1, "timezone": "UTC", "next_run": "2026-08-04 06:02:00", "next_run_human": "in 4 days"}}`

---

## PHP

### GET `/php`
**Permission:** `php` (view)

```json
{"php": {
  "default": "8.4", "panel_version": "8.4",
  "versions": [{
    "version": "8.4", "path": "/usr/bin/php8.4", "is_default": true,
    "source": "apt", "in_use_by_panel": true, "in_use_by": 0,
    "service": "php8.4-fpm", "ini_path": "/etc/php/8.4/fpm/php.ini",
    "status": "ready", "started_at": null, "reason": null, "message": null, "reference": null
  }, {
    "version": "8.3", "status": "installing", "started_at": "29-07-2026 05:20:11"
  }],
  "installable": [{"version": "8.5", "lifecycle": {"status": "active", "eol_date": "2027-01-01"}}]
}}
```

**`status`** on every row: `installing | ready | failed`. `ready` is never stored — detected from the filesystem.

**`failed` rows persist** until retried. `reason`: `package_not_found | apt_lock | no_space | network | worker | enable_failed | unknown`. `message` is localised. `reference` locates raw apt output.

**`installing` rows have no other fields** — nothing is on disk yet.

---

### PUT `/php/default`
**Permission:** `php` (manage)

**Request:** `{"default": "8.3"}`

**Response `200`:** `{"php": {"default": "8.3", …}}`

---

### POST `/php/versions`
**Permission:** `php` (manage)

**Request:** `{"version": "8.3"}`

**Response `202`:** queued (apt takes minutes). Already installed → `200`.

---

### DELETE `/php/versions/{version}`
**Permission:** `php` (manage)

`422` if the version is the panel's own, is currently the default, or is pinned by any site (site names returned in the message).

**Response `204`:**

---

### GET `/php/versions/{version}/ini`
**Permission:** `php` (view)

```json
{"php_ini": {"version": "8.4", "path": "/etc/php/8.4/fpm/php.ini", "contents": "; PHP config\n…"}}
```

---

### PUT `/php/versions/{version}/ini`
**Permission:** `php` (manage)

Replace the entire ini file. Requires `acknowledged: true`.

**Request:** `{"contents": "; PHP config\n…", "acknowledged": true}`

Sequence: back up → write → `php-fpm -t` → reload. On validation failure, previous file restored, no reload.

`422` on invalid ini.

---

### GET `/php/versions/{version}/extensions`
**Permission:** `php` (view)

**`status`** is the state of the *operation*, not the extension — `installing | ready | failed`. `ready` = nothing in flight, so `installed`/`enabled` can be trusted.

```json
{"extensions": [
  {"name": "mysql", "package": "php8.4-mysql", "modules": ["mysqli","mysqlnd","pdo_mysql"],
   "installed": true, "enabled": true, "builtin": false,
   "sapis": {"cli": true, "fpm": true}, "status": "ready"}
], "panel_required": ["curl", "mbstring"]}
```

---

### PUT `/php/versions/{version}/extensions/{extension}`
**Permission:** `php` (manage)

**Request:** `{"enabled": true}`

`on, not installed` → `202` (apt queued). `off` → `200` (unlinked, never purged). Built-in / panel-required → `422`.

---

## Node.js

### GET `/node`
**Permission:** `node` (view)

```json
{"node": {
  "manager": "fnm", "default": "20.11.0",
  "versions": [{
    "version": "20.11.0", "path": "/opt/fnm/node-versions/v20.11.0/installation/bin/node",
    "is_default": true, "source": "fnm",
    "npm_version": "10.2.4", "in_use_by": 7, "lifecycle": {"status": "lts", "eol_date": "2026-04-30", "lts_name": "Iron"}
  }],
  "system": {"version": "24.18.0", "path": "/usr/bin/node"},
  "installable": [{"version": "22.11.0", "lifecycle": {"status": "current"}}],
  "lifecycle_available": true
}}
```

Same `status` / `started_at` / `reason` / `message` / `reference` pattern as PHP. `npm_version` is read from *that version's own* npm.

---

### PUT `/node/default`
**Permission:** `node` (manage)

**Request:** `{"default": "20.11.0"}`

**Response `200`:** `{"node": {"default": "20.11.0", …}}`

---

### POST `/node/versions`
**Permission:** `node` (manage)

**Request:** `{"version": "20.11.0"}`

**Response `202`:** queued. Already installed → `200`.

---

### DELETE `/node/versions/{version}`
**Permission:** `node` (manage)

`422` if a site pins it or it is the default.

**Response `204`:**

---

### POST `/node/versions/{version}/npm`
**Permission:** `node` (manage`

Update npm inside a specific Node version.

**Response `200`:** `{"message": "npm updated to 10.8.0.", "npm_version": "10.8.0"}`

---

## Setup Page

### GET `/setup`
**Permission:** `setting` (view)

The same component list as the Services page — one read drives both screens.

```json
{"setup": {
  "complete": false, "status": "installing", "percent": 60,
  "key": "database", "label": "Installing Database",
  "stack": "lemp", "web_server": "nginx",
  "components": [
    {"key": "database", "title": "Database", "description": "MySQL or MariaDB",
     "state": "installing", "detail": null, "recommended": true,
     "action": null, "reason": null, "message": null, "retryable": false,
     "options": [
       {"value": "mariadb", "label": "MariaDB", "installed": false, "version": null,
        "installable": true, "recommended": true,
        "action": {"method": "POST", "endpoint": "/api/databases/engines/mariadb"}}
     ]},
    {"key": "php", "title": "PHP", "state": "installed", "detail": "8.4",
     "action": {"method": "POST", "endpoint": "/api/php/versions"}, "options": []}
  ]
}}
```

`state`: `installed | pending | installing | failed`. **`pending` means "not found"**, not "not tried". `action` is `null` when the panel cannot install this component (e.g. Redis, MongoDB).

---

## Integrations — Git Accounts

### GET `/integrations/git/providers`
**Permission:** `git` (view)

What each provider needs to connect.

```json
{"providers": {
  "github": {"name": "GitHub", "fields": ["token"], "token_label": "Personal Access Token", "token_type": "password"},
  "gitlab": {"name": "GitLab", "fields": ["token"], "token_label": "Personal Access Token", "token_type": "password"},
  "bitbucket": {"name": "Bitbucket", "fields": ["username", "app_password"], "token_label": "App Password", "token_type": "password"}
}}
```

---

### GET `/integrations/git/accounts`
**Permission:** `git` (view)

```json
{"git_accounts": [
  {"id": 1, "label": "Personal", "provider": "github", "provider_title": "GitHub", "username": "devuser", "status": "valid", "expires_at": null}
]}
```

---

### GET `/integrations/git/accounts/status`
**Permission:** `git` (view) | **Throttle:** 30/min

Live token health — checked on demand, never cached (tokens can be revoked at any time).

```json
{"statuses": [
  {"id": 1, "label": "Personal", "provider": "github", "provider_title": "GitHub",
   "status": "valid", "status_title": "Valid token",
   "expires_at": null, "expires_in_days": null, "checked_at": "29-07-2026 10:00:00"}
]}
```

---

### POST `/integrations/git/accounts`
**Permission:** `git` (manage) | **Throttle:** 20/min

**Request (GitHub):**
```json
{"label": "Work", "provider": "github", "token": "ghp_…"}
```

**Request (Bitbucket):**
```json
{"label": "Work", "provider": "bitbucket", "username": "devuser", "token": "APP_PASSWORD"}
```

**Response `201`:** `{"git_account": {"id": 2, "label": "Work", "provider": "github", "status": "valid"}}`

---

### PUT `/integrations/git/accounts/{account}`
**Permission:** `git` (manage) | **Throttle:** 20/min

**Request:** `{"token": "ghp_newtoken…"}`

**Response `200`:** `{"git_account": {...}}`

---

### POST `/integrations/git/accounts/{account}/test`
**Permission:** `git` (manage) | **Throttle:** 20/min

Verify the token is still valid with the provider.

**Response `200`:** `{"git_account": {"id": 1, "status": "valid"}}`

---

### DELETE `/integrations/git/accounts/{account}`
**Permission:** `git` (manage)

**Response `200`:** `{"deleted": true}`

---

### GET `/integrations/git/accounts/{account}/repositories`
**Permission:** `git` (view) | **Throttle:** 60/min

**Query:** `?search=shop&page=1`

```json
{"repositories": [
  {"name": "shop", "full_name": "devuser/shop", "url": "https://github.com/devuser/shop", "default_branch": "main", "private": true}
], "meta": {"page": 1, "has_more": false}}
```

---

### GET `/integrations/git/accounts/{account}/branches`
**Permission:** `git` (view) | **Throttle:** 60/min

**Query:** `?repository=devuser/shop`

**Response `200`:** `{"branches": ["main", "develop", "hotfix/payment"]}`

---

## Integrations — Storage Destinations (S3-compatible backup storage)

### GET `/integrations/storage/destinations`
**Permission:** `storage` (view)

```json
{"storage_destinations": [
  {"id": 1, "name": "S3 Backup", "provider": "s3", "bucket": "my-backups", "region": "eu-west-1",
   "last_tested_at": "28-07-2026 10:00:00", "last_test_success": true, "last_test_error": null}
]}
```

---

### POST `/integrations/storage/destinations`
**Permission:** `storage` (manage) | **Throttle:** 20/min

**Request:**
```json
{"name": "S3 Backup", "provider": "s3", "endpoint": "https://s3.amazonaws.com",
 "bucket": "my-backups", "region": "eu-west-1", "access_key": "AKIA…", "secret_key": "…", "path_prefix": "backups/"}
```

Also supports `provider: "local"` for a local disk path.

**Response `201`:** `{"storage_destination": {...}}`

---

### GET `/integrations/storage/destinations/{storageDestination}`
**Permission:** `storage` (view)

```json
{"storage_destination": {"id": 1, "name": "S3 Backup", "provider": "s3", "bucket": "my-backups", …}}
```

---

### PATCH `/integrations/storage/destinations/{storageDestination}`
**Permission:** `storage` (manage) | **Throttle:** 20/min

Update any field(s). Secrets omitted = unchanged.

**Request:** `{"name": "EU Backup", "bucket": "eu-backups"}`

**Response `200`:** `{"storage_destination": {...}}`

---

### DELETE `/integrations/storage/destinations/{storageDestination}`
**Permission:** `storage` (manage)

`422` if any backup target uses this destination.

**Response `204`:**

---

### POST `/integrations/storage/destinations/{storageDestination}/test`
**Permission:** `storage` (manage) | **Throttle:** 20/min

Probe credentials and reachability by uploading/reading/deleting a sentinel object.

**Response `200`:**
```json
{"test": {
  "success": true, "latency_ms": 142, "message": "Connection successful.",
  "error_class": null, "tested_at": "29-07-2026 10:00:00"
}}
```

Always `200` — the request itself succeeded, `test.success` carries the verdict.

---

## Utility

### GET `/timezones`
Unauthenticated.

```json
{"timezones": [{"value": "UTC", "label": "UTC"}, {"value": "Europe/London", "label": "London (GMT+1)"}, …]}
```

---

### GET `/health`
Unauthenticated. Health check for load balancers / uptime monitors.

**Response `200`:** `{"status": "ok"}`

---

## Activity Log Types & Verbs

Activity entries are written for every mutation. `type` and `action` are separate columns. Use `GET /admin/activity-log/filters` to get the live catalog — this is only a reference index.

| type | action |
|------|--------|
| `user` | registered, logged_in, password_changed, impersonation_started, impersonation_stopped |
| `role` | created, updated, permissions_updated, deleted |
| `system_user` | created, deleted, sudo_toggled, shell_changed, ssh_access_changed, ssh_key_added, ssh_key_removed |
| `application` | created, updated, deleted, provisioned, provision_failed, deployed, deploy_failed, disabled, enabled, domain_added, domain_removed, certificate_issued, certificate_uploaded, certificate_deleted, file_edited, file_deleted, directory_created, permissions_fixed, php_isolated, php_unisolated, php_settings_updated, environment_updated, environment_restored, worker_created, worker_updated, worker_deleted, worker_started, worker_stopped, worker_restarted, deploy_script_updated, deploy_settings_updated, staging_created, staging_pushed |
| `database` | created, deleted, user_created, user_updated, user_deleted, export_queued, export_completed, export_failed, export_deleted, optimized, repaired, imported |
| `backup` | configured, run, completed, failed, downloaded |
| `disk_cleaner` | cleaned, schedule_updated |
| `cronjob` | created, updated, deleted |
| `service` | started, stopped, restarted, reloaded, enabled, disabled, config_test |
| `fail2ban` | installed, updated, ban_added, ban_removed, all_bans_removed |
| `firewall` | rule_added, rule_updated, rule_deleted, enabled, disabled |
| `git_account` | connected, updated, test_passed, test_failed, disconnected |
| `node` | install_started, uninstalled, npm_updated, default_changed |
| `setting` | updated, reboot_requested, reboot_scheduled, reboot_schedule_removed |
| `panel_update` | started, completed, failed |
