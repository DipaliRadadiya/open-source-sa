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

Paged. `?search=` matches name **and** description; `?sort=name|created_at` (prefix `-` for descending, default `name` ascending); `?per_page=10|20|30|50|100`, default 10. Responds `meta{current_page, per_page, total, last_page}`.

```json
{"roles": [{
  "id": 1, "name": "Administrator", "slug": "administrator", "is_system": true, "description": null,
  "permissions": [
    {"level": "server", "name": "system_user", "title": "System User", "access": "manage",
     "permissions": {"view": true, "manage": true}}
  ],
  "created_at": "23-07-2026 10:00:00", "created_at_human": "3 weeks ago"
}]}
```

- `permissions` is an array of **objects**, not permission-name strings.
- `access` is `none` | `view` | `manage` — bind the three-way control to this one field. The `permissions.{view,manage}` pair is the same grant in the older shape and is still returned.
- `title` is localised (it follows `Accept-Language`, matching the catalog).
- Only permissions that have a row for this role appear. Merge against `GET /admin/permissions` to render the ungranted ones as `none`.

`is_system: true` — system roles cannot be renamed, deleted, or have their permissions changed (422).

---

### POST `/admin/roles`
**Permission:** `access-admin` (manage)

**Request:**
```json
{"name": "Developer", "description": "Can deploy",
 "permissions": [
   {"level": "server", "name": "application", "access": "manage"},
   {"level": "server", "name": "database", "access": "view"},
   {"level": "server", "name": "logs", "access": "none"}
 ]}
```

Each item needs `level` **and** `name` — the same `name` can exist at two levels. Send `access` (`none` | `view` | `manage`); the older `{"view": bool, "manage": bool}` pair is still accepted, and `manage` always implies `view` however it is sent.

**Response `201`:** `{"role": {...}}`

---

### PUT `/admin/roles/{role}`
**Permission:** `access-admin` (manage)

**Request:** same shape as `POST /admin/roles`. The `permissions` array **replaces** the role's grants — send the full set, not a delta.

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

### PUT `/admin/users/{user}/reset-password`
**Permission:** `access-admin` (manage)

**Request:** `{"password": "…", "password_confirmation": "…"}`

`password_confirmation` is required and must match. Minimum 10 characters, mixed case, at least one number.

**Response `204`:** empty body.

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

Everything the role form needs: the flat catalog, the same rows pre-grouped, and the names of the three states a grant can hold.

```json
{
  "permissions": [
    {"name": "dashboard", "title": "Dashboard", "level": "server", "sub_level": "server", "sub_level_title": "Server", "icon": null, "url": null},
    …
  ],
  "groups": [
    {"level": "server", "sub_level": "server", "sub_level_title": "Server", "permissions": [ … ]},
    {"level": "application", "sub_level": "application", "sub_level_title": "Application", "permissions": [ … ]}
  ],
  "access_levels": [
    {"key": "none",   "title": "No access",    "description": "Hidden from this user. The menu item does not appear at all."},
    {"key": "view",   "title": "Read only",    "description": "Can open the screen and see everything on it, but cannot change anything."},
    {"key": "manage", "title": "Read & write", "description": "Can open the screen and make changes — create, edit and delete."}
  ]
}
```

- `groups` is `permissions` bucketed by `level` + `sub_level` — use it for section headers and a select-all per section. Keyed on **both**, because `logs` exists at server level and `app_log` at application level as separate permissions.
- `access_levels` is the vocabulary for the grant control. Render the options from this array; never hardcode the labels or the order — all three are localised from `Accept-Language`.
- The catalog carries **no grant state**. A role's own grants come from the roles endpoints below.

---

### POST `/admin/permissions/sync`
**Permission:** `access-admin` (manage)

Re-runs the permission catalog seeder and re-syncs the protected Administrator role, so permissions shipped by a new panel version become available without a manual step. Idempotent — safe to press twice.

**Response `200`:** the same body as `GET /admin/permissions`, plus `"synced": 42` (the number of permissions in the catalog afterwards).

Also runs automatically on every deploy; this endpoint exists for the case where the panel was updated some other way.

---

## Admin — Installation Self-check

### GET `/admin/doctor`
**Permission:** `access-admin` (view) | **Throttle:** 10/min

Runs the same checks as the `panel:doctor` CLI command against the live server: sudo access, service state, the panel's own health endpoint. Read-only — no check changes anything.

Throttled because each call shells out and makes an outbound HTTP request. It is a diagnostic, not something to poll.

```json
{"doctor": {
  "healthy": true, "passed": 11, "failed": 0, "warnings": 1,
  "checks": [
    {"key": "privilege", "title": "Privileged commands", "status": "pass", "detail": "…", "fix": null},
    {"key": "frontend_build", "title": "Frontend build", "status": "warn", "detail": "…", "fix": "Rebuild the frontend."}
  ]
}}
```

Twelve checks: `privilege`, `binaries`, `services`, `web_server`, `driver_contention`, `database`, `queue`, `writable_paths`, `health_endpoint`, `php_isolation`, `account_locks`, `frontend_build`.

`status` is `pass`, `warn` or `fail`. **`healthy` counts failures only** — a warning is worth showing and not worth blocking on, so don't derive health from `warnings`. `title` and `fix` arrive localized; `fix` is `null` when there is nothing to do. A check that throws is reported as a `fail` rather than aborting the run.

---

## Admin — Activity Log (admin-wide)

### GET `/admin/activity-log/filters`
**Permission:** `access-admin` (view)

Returns every `type` and `action` value the system has ever recorded, for building filter dropdowns.

```json
{"types": ["user", "role", "system_user", "application", "database", …],
 "actions": {"all": ["created", "updated", "deleted", …], "user": ["registered", "logged_in", "password_changed", …]},
 "scopes": [{"value": "account", "label": "Account"}, {"value": "server", "label": "Server"}]}
```

`scopes` comes back with its labels **already translated into the viewer's locale** — the frontend must not carry a second copy of these names in eight languages. Same reason the sidebar section headers come from the API.

---

### GET `/admin/activity-log`
**Permission:** `access-admin` (view)

Paginated (**`per_page` defaults to 10**, not 20). Filters: `filter[user_id]`, `filter[scope]`, `filter[type]`, `filter[action]`, `search` (free-text on type + action + actor name).

**`filter[scope]` is the coarse one** — pass `account` or `server` and the backend expands it to that scope's type list server-side. Do not build it client-side by sending several `filter[type]` values; there is no multi-type filter, and the map lives in `config/activity.php`.

```json
{"activity_log": [{"id": 1, "type": "user", "action": "registered", "scope": "account", "description": "Registered", "user": {"id": 1, "username": "admin"}, "is_system": false, "created_at": "…", "created_at_human": "2 hours ago"}], "meta": {"current_page": 1, "per_page": 20, "total": 150, "last_page": 8}}
```

`description` is built at read time from `__('activity.'.$type.'.'.$action, $properties)` in the viewer's locale — not stored. **The `properties` bag itself is not returned** on any activity endpoint: it is the input to that sentence, not a field to read values out of.

`scope` is which half of the panel the row is about — `account` (users, roles, permissions, central consent) or `server` (everything operational) — so the frontend can badge or group without keeping its own copy of the type→scope map. It is null for a type not yet in the map.

**`is_system: true` means no person did this** — a scheduled reboot, an automatic disk clean, a deploy from a git webhook. It is stated outright rather than left to be inferred from a null `user`, and the backend deliberately does not paper over it by writing some admin's id onto a system action.

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

### GET `/admin/panel-update`
**Permission:** `access-admin` (view) | **Throttle:** 30/min

Pass `?refresh=1` for the "check now" button — it bypasses the cached release lookup and calls the release host. Throttled, so don't poll it.

```json
{"panel_update": {
  "installed": {
    "version": "1.0.0", "commit_hash": "a1b2c3d…", "commit_short": "a1b2c3d",
    "branch": "main", "source": "tag",
    "is_git_checkout": true, "has_local_changes": false
  },
  "available": {
    "version": "1.1.0", "published_at": "2026-08-10T09:00:00Z",
    "notes": "…", "url": "https://github.com/…/releases/tag/v1.1.0"
  },
  "update_available": true,
  "preflight": {
    "ready": true,
    "checks": [
      {"key": "git_checkout", "passed": true, "detail": null},
      {"key": "clean_working_tree", "passed": true, "detail": null},
      {"key": "free_disk", "passed": true, "detail": "8 GB"},
      {"key": "free_memory", "passed": true, "detail": "1 GB"},
      {"key": "writable_path", "passed": true, "detail": null}
    ]
  },
  "latest_run": null
}}
```

Bind the update button to both `update_available` and `preflight.ready` — the `checks` list is for explaining a preflight "no". `update_available` is true only when the published version is strictly newer than the installed version; `POST` returns a localized `422` error on `version` rather than allowing a reinstall or downgrade. `is_git_checkout: false` means an in-place update is impossible on this box (a packaged install without `.git` cannot be moved by `git checkout`), and preflight will say so.

`installed.source` is one of `tag | tag-ahead | file | env | unknown`. `tag-ahead` means HEAD is newer than its nearest reachable release tag; the reported version is that safe comparison baseline, so the latest release is not incorrectly offered as a downgrade.

`latest_run` is `null` until an update has been started; afterwards it is the same object `POST` and the status endpoint return. It is reconciled against the on-disk state file before answering, so a run whose process died along with the panel restart is still reported correctly.

---

### POST `/admin/panel-update`
**Permission:** `access-admin` (manage) | **Throttle:** 3/min

Starts the update and returns immediately — the runner is detached and the panel is about to restart itself, so there is nothing to wait for.

**Response `202`:** `{"panel_update": {...}}` (same shape as the status endpoint below)

---

### GET `/admin/panel-update/{panelUpdate}`
**Permission:** `access-admin` (view) | **Throttle:** 120/min

Deliberately cheap — poll this every few seconds while the progress bar moves.

```json
{"panel_update": {
  "id": 4, "status": "running", "status_title": "Running",
  "current_step": "migrate", "current_step_title": "Running migrations",
  "step_number": 6, "total_steps": 14,
  "from_version": "1.0.0", "to_version": "1.1.0",
  "from_commit": "a1b2c3d", "to_commit": "d4e5f6a",
  "reason": null, "reason_title": null,
  "rolled_back": false, "reference": null,
  "output": "Running database migrations…", "output_truncated": false,
  "started_at": "12-08-2026 10:15:00", "started_at_human": "2 minutes ago",
  "finished_at": null, "finished_at_human": null
}}
```

Draw the progress bar from `step_number`/`total_steps` — don't hardcode the step list. `output` is the bounded, redacted tail of the detached update log; `output_truncated: true` means older bytes were omitted. Terminal controls, authorization headers, cookies, common secret assignments/query parameters, and private keys are removed before this admin-only field is returned. On failure, `reason` is a classified key and `reason_title` is it localized; `reference` points at the server log entry. `rolled_back` says whether the previous release was restored.

---

## Activity Log (own history)

### GET `/activity-log/filters`
Auth-gated — no permission needed.

Same `{types, actions, scopes}` shape as `/admin/activity-log/filters`, so one component renders both. **The contents are built from the caller's own rows, not from the catalog** — a user who has never touched databases does not get a `database` option that is guaranteed to match nothing. A brand-new account therefore gets empty arrays; that is correct, not a failure.

---

### GET `/activity-log`
Auth-gated — no permission needed. Own history only; the self-scope is applied first and no filter combination can widen it to another user's rows.

Paginated, `per_page` defaults to **10**. Filters: `filter[scope]`, `filter[type]`, `filter[action]`, `search` (type + action only — there is no actor to search, every row is yours).

```json
{"activity_log": [{"id": 1, "type": "user", "action": "logged_in", "scope": "account", "description": "Logged in", "is_system": false, "created_at": "12-08-2026 09:00:00", "created_at_human": "2 hours ago"}], "meta": {"current_page": 1, "per_page": 10, "total": 42, "last_page": 5}}
```

**The `user` key is absent entirely** — not null. The relation is deliberately not loaded (it would be you on every row), and `whenLoaded` drops the key rather than emitting null. Do not read `row.user.username` here.

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

What this server is and what it can run — drives which site types are offered.

```json
{"capabilities": {
  "stack": "lemp",
  "web_server": "nginx",
  "capabilities": {"php": true, "node": false},
  "source": "installer",
  "verified_at": "29-07-2026 10:00:00",
  "server_ip": "167.233.229.184",
  "temporary_domain_suffixes": ["nip.io"]
}}
```

`stack` is how the box was **built** (`lemp|lamp|ols|mern`, or `null` for a
server migrated in from another panel); the inner `capabilities` object is what
it can run **now**. They diverge legitimately — installing Node on a LEMP box
adds the capability without changing how the box was built — so **filter on
`capabilities`, never on `stack`**.

`source` is `installer` (our install script wrote the row) or `detected` (the
row was missing and the panel probed the box once). `web_server` is
`nginx|apache|openlitespeed`; `mern` is never a value here, because MERN uses
nginx.

**`server_ip` is the public IP, or `null`.** It is detected from the local
route table and cached for an hour. It is deliberately `null` — not a private
address — on a NAT'd instance (most of AWS, GCP, Azure, and anything with a
floating IP), where the machine cannot see the address the world reaches it on.
Callers must handle `null` and say "we could not determine this server's
address" rather than offering a hostname that resolves nowhere. This is a
*different value* from `facts.ip` on `GET /server/facts` — see that endpoint.

`temporary_domain_suffixes` is every suffix on offer for the throwaway
`<name>.<ip>.<suffix>` hostname the create form builds. The same list decides
what counts as a temporary domain, so the frontend cannot invent a hostname the
backend then mistakes for the user's own.

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

**`options` comes in two shapes, and both are already handled by rendering `label` and submitting `value`.** A plain array of strings (`["8.4", "8.3"]`) means value and label are the same. Objects (`{"value": …, "label": …}`) mean they differ — always render `label`.

That distinction now matters for the locale and country pickers. They used to label every option with its own code, so choosing a site language meant picking between `he_IL` and `hi_IN`. They now carry real names, **in the viewer's locale**, built at read time:

```json
"options": [
  {"value": "af", "label": "Afrikaans"},
  {"value": "sq", "label": "Albanian"},
  {"value": "ar", "label": "Arabic"}
]
```

`value` is unchanged and still the exact code the installer takes — nothing about the create request changes. Two consequences for the UI: the lists are **sorted by label in the viewer's language** (so `Åland Islands` sorts before `Albania`, which byte order gets wrong), and they are no longer grouped with the common defaults first — rely on the field's `default` for pre-selection rather than on position.

`available: false` → card is greyed. `installable_runtime` names the missing runtime that would fix it.

---

## Applications

### GET `/applications`
**Permission:** `application` (view)

**Paginated, ten per page.** Was every application in one response, which is also why it was slow: the resource asks systemd for the state of every application that runs a process, so the page size bounds a number of subprocesses, not just a number of rows.

| Query | |
|---|---|
| `page` | 1-based, standard Laravel paging |
| `per_page` | default **10**, max 100 — `422` above that |
| `search` | free text over **name and domain**, max 255 chars |
| `filter[status]` | `pending` · `provisioning` · `active` · `failed` |
| `filter[site_type]` | any name from `GET /site-types` |
| `sort` | `created_at` · `name` · `domain` · `status` · `site_type` · `directory_size_bytes` — prefix `-` for descending, default `-created_at` |

Filters combine with each other and with `search` — all are AND, and all survive a sort.

**Sort on the server, not in the table.** The list is paged, so an in-table sort orders the current page and nothing else — which looks correct and is not. An unlisted column is a **`422`**, including `owner`: it lives on a relation, so sorting by it would mean a join, and the list can already be searched by username.

**Name sorting is case-insensitive.** Capitalization does not split the list into uppercase and lowercase groups: `alpha`, `apple`, `Banana`, `Zebra` is the ascending order. Names that differ only by case remain stable across pages through the row-ID tie-breaker.

**Sorting by `directory_size_bytes` puts never-measured sites at the small end** — before a site of `0` bytes, not mixed in with it. A site nothing has walked yet is not an empty site, and the two must not be confused. Descending therefore reads "biggest first, unknown last". This is set explicitly rather than left to the database: SQLite and MySQL sort NULL first ascending, PostgreSQL sorts it last, and the panel supports all three.

Note the value is the **last measured** size — `null` for a site created in the last minute, until the per-minute sweep reaches it. The row already says so.

**Both filters are validated against the real sets, so a wrong value is a `422`, not an empty list.** That matters more than it sounds: answering a typo with `{"applications": []}` reads to the user as *"you have no applications"*. Send `null` or omit the key to clear a filter.

Filtering by system user or by server is deliberately not offered — ask rather than filtering client-side over a single page.

```json
{
  "applications": [ … ],
  "meta": {"current_page": 1, "per_page": 10, "total": 14, "last_page": 2}
}
```

`meta` describes the **filtered** set, so page 2 of a one-result search is not an empty screen with a next button.

Every application in the list carries the **same full shape** as `GET /applications/{application}` below — this is one resource, used everywhere. The list is not a trimmed variant.

```json
{"applications": [{
  "id": 1, "name": "shop", "domain": "shop.example.com",
  "site_type": "wordpress", "site_type_title": "WordPress",
  "serving_profile": "php", "rendering_type": null,
  "status": "active", "status_title": "Active", "deployed": true,
  "is_disabled": false, "disabled_at": null,
  "system_user": {"id": 1, "username": "siteowner"},
  "php_version": "8.4", "node_version": null,
  "created_at": "25-07-2026 14:30:00", "created_at_human": "2 weeks ago"
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
  "domain_type": "custom",
  "site_type": "wordpress",
  "php_version": "8.4",
  "system_user_id": 1,
  "site_user_password": "…",
  "web_root": "/",
  "git_account_id": null,
  "repository": null,
  "branch": null,
  "package_manager": null,
  "build_command": null,
  "deploy_script": null
}
```

`domain` is trimmed, lowercased, and must be globally unique across every application domain — not only other primary domains. A duplicate returns the normal localized `422` validation response on `domain`. Creation is atomic even if two requests race for the same hostname: no partial application row is retained, so the same request can be corrected and retried safely.

**Response `201`:**
```json
{"application": {"id": 1, "name": "shop", …, "status": "provisioning"}}
```

**`deploy_script` matters most on a `git` site, and only there.** Creating a git site queues an automatic first deploy as soon as provisioning finishes (`DeploymentTrigger::Initial`), and that deploy runs the script — so a script added afterwards on the Deployment screen has already missed the run that decides whether the site comes up. For a Laravel repository whose `php artisan migrate` lives here, that is the difference between a working site and a 500 until someone deploys again by hand.

`deploy_script` wins over `build_command` when both are given, exactly as on any later deploy. Windows line endings are normalised on save.

**`domain_type` is `custom` or `temp`, and it is optional — but send it.** It records whether the user brought their own name or took the panel's offered `<name>.<ip>.nip.io` hostname. Omitted, the domain is treated as the user's own. It no longer decides whether the site can have a Let's Encrypt certificate — that is now one rule for every hostname, "does the name resolve to this server" — but it is still worth sending: it is how the panel knows a wildcard-DNS name resolves by construction and needs no lookup, and it is surfaced as `is_test` on the domain. A wildcard-DNS suffix is detected server-side regardless of the label, so an omitted or wrong value is corrected rather than trusted.

Never send the raw field list — `GET /site-types` publishes the fields for each type, including this one, with localized labels and help text.

**No other site type deploys after creation.** The ten marketplace PHP types and four marketplace Node types install during provisioning; `php` and `static` sites start from a placeholder. Only `git` has `app_deployment` in its feature list at all.

---

### GET `/applications/{application}`
**Permission:** `application` (view)

Full application record. Poll this while `status` is `provisioning` or `deploying`.

**Link to `url`, never build one from `domain`.** `url` is `http://…` until the site has a servable certificate and `https://…` afterwards. Assembling `https://${domain}` in the client — which three screens used to do — produces a dead link for every site that has not been issued a certificate yet, which is every site for the first few minutes of its life.

**`fail2ban_enabled` means "this site has a jail configured"** — it is derived from the same jail this resource's sibling endpoint reports, so a dashboard card and the site's fail2ban screen can no longer disagree. It went the other way until 2026-08-22: the field came from a stored boolean whose only writer was unreachable, so it read `false` for every site on the server, including ones with a jail actively running. **Do not read the `fail2ban_enabled` column directly** — it is orphaned and stays only because dropping it needs a schema change.

It does **not** mean fail2ban is running. That is a server-wide fact; `GET /services` answers it. Live jail state — banned addresses, counters — is on `GET /applications/{id}/fail2ban`, which costs a `fail2ban-client` call and so is deliberately not in this response.

```json
**`path` vs `document_root` — they are not the same directory, and picking the wrong one fails silently.**

- **`document_root`** is what the web server serves. Use it to *show* somebody where their site is.
- **`path`** is where the application's own command-line tools run. **This is the value to substitute for `{path}`** in a cron command preset or a deploy script.

They are equal for fifteen of the seventeen site types, because most applications unpack into the directory they are served from. They differ for **Craft** (`document_root` ends `/web`) and **Statamic** (`/public`), whose `craft` and `please` binaries sit one level above the served directory. `php {path}/craft queue/run` handed a `document_root` points at a file that does not exist, and cron reports nothing — so substitute `path`, always, and never derive one of these from the other.

```json
{"application": {
  "id": 1, "name": "shop", "domain": "shop.example.com",
  "url": "https://shop.example.com",
  "document_root": "/home/siteowner/shop/public_html",
  "path": "/home/siteowner/shop/public_html",
  "site_type": "wordpress", "site_type_title": "WordPress",
  "serving_profile": "php", "rendering_type": null,

  "status": "active", "status_title": "Active", "deployed": true,
  "is_disabled": false, "disabled_at": null,

  "basic_auth_enabled": false, "basic_auth_username": null,

  "ai_bot_policy": "block_training", "ai_bot_policy_title": "Block AI training crawlers",
  "waf_supported": true,
  "waf_enabled": true, "waf_mode": "detect", "waf_mode_title": "Just watch, don't block",
  "waf_categories": ["query_string", "request_uri", "user_agent", "referrer", "cookie", "method"],
  "fail2ban_enabled": false,

  "is_staging": false, "production_application_id": null,
  "cloned_from_application_id": null,

  "system_user": {"id": 1, "username": "siteowner"},

  "php_version": "8.4", "node_version": null, "app_port": null,
  "web_root": "/", "build_command": null, "start_command": null,

  "has_process": false,

  "git_account_id": null, "repository": null, "repository_url": null, "branch": null,

  "webhook": {
    "enabled": false, "provider": null, "url": null, "secret": null,
    "verification": null, "last_delivered_at": null, "last_delivered_at_human": null
  },

  "settings": {},
  "steps": [], "failed_step": null,
  "failed_reason": null, "failed_reason_title": null,
  "provisioning_started_at": null, "provisioning_started_at_human": null,
  "last_commit": null, "last_deployed_at": null, "last_deployed_at_human": null,
  "reference": null,

  "created_at": "25-07-2026 14:30:00", "created_at_human": "2 weeks ago"
}}
```

**`failed_reason` is usually `null`, and that is not an omission.** For most
failures `failed_step` says where it broke and `reference` points at the
server-ops log entry holding the command's own output — together they say more
than any category invented here would, and a wrong reason sends the user to fix
something that was never broken.

It is set only where the exit status genuinely identifies the cause. Today that
is one code, `out_of_memory`: a step killed by the kernel's OOM killer exits
137 having written **nothing at all**, so the reference names an empty log and
the panel would otherwise have no way to explain what happened. Render
`failed_reason_title` when present — it is localized in the viewer's locale —
and fall back to `failed_step` + `reference` when it is `null`.

**`status` is `pending` · `provisioning` · `active` · `failed`** — there is no `running`.

**Use `provisioning_started_at` for elapsed time, never `created_at`.** It is
restamped on every run *including a retry*, whereas `created_at` is the row's
birthday — so after a retry, elapsed time built on `created_at` counts from the
original attempt and keeps climbing. `null` on a site that has never been
provisioned.

**`status` and `is_disabled` are two different axes.** A healthy site can be paused — the vhost is swapped to an "unavailable" page — without its provisioning `status` changing. The enable/disable button switches on `is_disabled`, never on `status`. `deployed` is simply `status === "active"`, exposed so a "pending" badge cannot imply the site is reachable.

**Fields that are absent, not null, unless the endpoint loads them.** These use `whenLoaded`, so the key is missing from the JSON entirely — check with `in` / `hasOwnProperty`, not for `null`:

| field | present only on |
|---|---|
| `bot_blocked`, `bot_allowed` | `PUT /applications/{id}/bot-blocker` |
| `waf_exceptions`, `waf_custom_rules` | `GET` and `PUT /applications/{id}/waf` |
| `has_staging` | never — see the note under Staging Area |
| `process` | only when `has_process` is true |

**`directory_size_bytes` is always present, and null until measured.** It used to be
absent in that case, which made an unmeasured site indistinguishable from an endpoint
that does not report size — render `—` on null rather than hiding the column.

It travels with **`directory_size_measured_at`** (and `_human`). Show them together: nothing
recomputes this on a timer, so a size on its own reads as current when it may be days old.
It is set by a deploy, by a file change (about a minute later — see
`POST /applications/{id}/directory-size`), and by that endpoint.

`has_process` is null-safe to read everywhere and tells you whether to render process controls at all: PHP and static sites have nothing to run, so the answer is "render nothing", not "render a disabled button".

**`webhook.secret` is returned in full, deliberately** — the user has to paste it into their repository settings and will come back for it. `webhook.url` is assembled server-side so the frontend never builds the path or gets the host wrong. `webhook.verification` is `signature` or `token`; `token` means a plaintext shared value, which only GitLab has — offer the user the stronger signing token when you see it.

`settings` is always an object (`{}` when empty), never `[]`. `steps` is a genuine list and stays `[]`.

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

### POST `/applications/{application}/directory-size`
**Permission:** `application` (view) | **Throttle:** 10/min

Measure this site's directory **now**, and store the result.

**Response `200`:** `{"directory_size": {"size": 5242880, "size_human": "5 MB", "measured_at": "15-08-2026 16:40:00"}}`

Its own call rather than something the listing does. `du` walks every inode, so the cost is the site's **file count** — anything with `node_modules` is a hundred thousand of them — and doing that per row would make the Applications screen as slow as the heaviest site on the box.

**The size keeps itself current without this.** Thirteen file operations (write, upload, copy, compress, extract, delete, the bulk pair, and the trash ones) queue a re-measure, **unique per application and delayed ~60 seconds**. A bulk delete of fifty files walks the site once, about a minute after the user stops — not fifty times while they work. Rename and chmod do not trigger it: they move bytes that are already counted.

So after a file operation, **do not expect `directory_size_bytes` to change on the next fetch.** It updates roughly a minute later. Either re-fetch then, or simply render `directory_size_measured_at` and let the number catch up on its own. This endpoint is the "measure it now" button for when the user will not wait.

A failed measure changes nothing — the previous figure and its date stand, because a stale number with an honest date beats a blank.

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

Delete the application record. Optionally delete its data.

**Request body (all optional):** `{"remove_files": false}`

**Always removed**, whatever `remove_files` says — these are the panel's own artefacts, and every one of them breaks something if it outlives the site:

- the vhost and the systemd unit
- the PHP-FPM pool *(an orphan naming a deleted Linux user stops php-fpm starting **for the whole server**)*
- worker units *(otherwise left enabled and restarting on boot)*
- the fail2ban jail
- the Let's Encrypt renewal *(otherwise it renews forever, spends rate limit, and mails the user about a site they removed)*

**Removed only with `remove_files: true`:**

- the site's files
- **its backups, archives included** — they cascade out of the database either way, so leaving them would strand multi-gigabyte objects in the storage destination that the panel can no longer see or delete. They follow this flag rather than going unconditionally because a backup is the copy that makes a mistaken deletion survivable, and deleting the site is the mistake most worth undoing.

So `remove_files: true` means **"destroy this site's data"**, not "tidy up the directory". Confirm it in the UI accordingly.

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
{"domains": [{
  "id": 1, "domain": "shop.example.com",
  "type": "primary", "type_title": "Primary",
  "redirect_to": null, "redirect_status": null,
  "is_test": false,
  "dns_verified": true,
  "dns_verified_at": "26-07-2026 09:00:00", "dns_verified_at_human": "2 hours ago",
  "dns_resolved_ip": "203.0.113.10",
  "behind_proxy": false,
  "certifiable": true,
  "created_at": "25-07-2026 14:30:00", "created_at_human": "2 weeks ago"
}]}
```

`type` is one of `primary`, `alias`, `redirect` — there is no `additional`. `redirect_to` and `redirect_status` (301 / 302) are only meaningful on a `redirect` row and null elsewhere.

**The DNS fields are what gate the SSL button.** `dns_verified` is derived (`dns_verified_at !== null`) and means "this name currently resolves to this server". Do not offer Let's Encrypt while it is false: Let's Encrypt permits five authorisation failures per hostname per hour, so a guess is expensive.

`behind_proxy` is broken out on its own because it is the single most common support question this screen produces. Cloudflare's proxy answers on its own addresses, so `dns_resolved_ip` will not be this server and HTTP validation never arrives — "DNS looks fine but SSL fails". Show the cause; don't let the user hunt for it.

`certifiable` is whether this name can go on a certificate, and it means exactly one thing: the name resolves to this server (`dns_verified_at` is set). The suffix is not part of the question — a wildcard-DNS name resolves here by construction and is certifiable like any other.

`is_test` still tells you what kind of hostname it is, and the panel uses it to skip a DNS lookup that cannot fail. It is a fact about the name, not a verdict on it.

---

### POST `/applications/{application}/domains`
**Permission:** `app_domain` (manage)

**Request:** `{"domain": "www.example.com"}`

**Response `201`:** `{"domain": {…}}` — the full domain object above.

---

### POST `/applications/{application}/domains/{domain}/verify`
**Permission:** `app_domain` (view)

Re-check DNS for this domain. Returns the full domain object with `dns_verified`, `dns_verified_at`, `dns_resolved_ip` and `behind_proxy` refreshed.

---

### POST `/applications/{application}/domains/{domain}/primary`
**Permission:** `app_domain` (manage)

Promote this domain to primary and synchronize the application's own canonical URL before applying the vhost. The canonical URL uses HTTPS only when the current certificate is servable **and covers the new hostname**; otherwise it uses HTTP, avoiding an application-level redirect to a name whose certificate is invalid.

The transition is atomic from the caller's point of view. If URL synchronization or vhost application fails, the previous primary domain, application-domain mirror, canonical URL, and vhost are restored best-effort and the request fails; do not update the UI optimistically before the `200` response.

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
  "id": 1,
  "type": "letsencrypt", "type_title": "Let's Encrypt",
  "status": "active",
  "domains": ["shop.example.com", "www.example.com"],
  "missing_domains": [],
  "stale_domains": [],
  "force_https": true,
  "auto_renew": true, "renewable": true,
  "issued_at": "25-07-2026 14:31:00", "issued_at_human": "2 weeks ago",
  "expires_at": "01-09-2026 00:00:00", "expires_at_human": "in 3 weeks",
  "served_expires_at": "01-09-2026 00:00:00", "served_checked_at": "15-08-2026 03:00:00",
  "serving_stale": false,
  "days_remaining": 21,
  "expired": false, "expiring_soon": false,
  "message": "Active until 1 September 2026."
}}
```

There is **no `issuer` field** — use `type` / `type_title`.

**`domains` is what the certificate covers; `missing_domains` is what the site answers to but the certificate does not.** They diverge the moment someone adds a domain, and that divergence is exactly the failure this panel exists to catch: the browser reports it, the server logs nothing. A non-empty `missing_domains` should prompt a re-issue, not a warning buried in a tooltip.

**`stale_domains` is the opposite direction, and it is the more urgent of the two.** It lists names on the certificate that the site no longer has — usually because someone removed an alias. It matters because **certbot re-validates every name in a certificate and fails the whole renewal if any one of them cannot be validated**: a single removed hostname silently stops the certificate renewing for the domains that are still fine, and the first symptom is a browser warning up to ninety days later.

So the two read differently in the UI. `missing_domains` is "this name has no HTTPS" — visible immediately, fix when convenient. **`stale_domains` is "this certificate has stopped renewing"** — invisible until it is fatal, and the fix is a re-issue. Non-empty on a `letsencrypt` certificate deserves a warning, not a hint. Always empty for `self_signed` and `custom`, which nothing renews.

**`expires_at` is the certificate on disk. `served_expires_at` is the one the web server is actually handing to visitors.** They agree on a healthy site. When they do not, a renewal landed on disk and never reached the running process — so the countdown above looks healthy while every visitor gets a browser warning. It is the one certificate failure with no other symptom in the panel, because everything else reads the file.

**`serving_stale`** is the answer in one field: `true` means the site is serving something older than the file, `false` means they match, and **`null` means nobody has managed to look** — nothing listening on 443, or TLS refused. Null must not render as a tick; "we did not check" and "it is fine" are different statements. `served_checked_at` is stamped even when the read fails, so the two are distinguishable.

Read over loopback with SNI, once a day, so it costs nothing and works on a server behind NAT. A site behind Cloudflare reports *this server's* certificate rather than Cloudflare's, which is the one being asked about.

`renewable` is a property of the type — nothing can renew an uploaded or self-signed certificate — while `auto_renew` is the user's setting. Show a renewal date only when `renewable` is true.

`expiring_soon` is its own flag rather than something the frontend computes from `days_remaining`, so the threshold is one decision in one place (and can move when certificate lifetimes shrink).

`message` is always present: a sentence in the *viewer's* locale. `reason` (a classified code) and `reference` appear **only when `status` is `failed`** — the keys are absent otherwise. Neither ever carries certbot's own output, which contains paths, order URLs and occasionally the account key location.

**Response `200` with `certificate: null`:** no cert installed yet.

The same response carries `available_types` — what this site can actually be given, and why not, where it cannot:

```json
{"available_types": [
  {"type": "letsencrypt", "label": "Let's Encrypt", "available": false, "recommended": false, "renewable": true,
   "reason": "None of this site's domains point at this server yet. Add a DNS A record for one, wait for it to propagate, then try again."},
  {"type": "self_signed", "label": "Self-signed", "available": true, "recommended": true, "renewable": false,
   "reason": "Encrypts traffic immediately and works on any domain, including test and internal ones. Browsers will show a warning…"},
  {"type": "custom", "label": "Uploaded", "available": true, "recommended": false, "renewable": false, "reason": null}
]}
```

- Drive the SSL screen off this array. Disable a type when `available` is `false` and show its `reason`; pre-select the one with `recommended`.
- `reason` on an **available** type is informational (the self-signed browser warning), not a blocker — check `available`, not `reason`.
- **There is one condition, and it is the same for every hostname: does the name resolve to this server.** A wildcard-DNS name (`*.nip.io`, `*.sslip.io`) does — that is what it is for — so it is offered Let's Encrypt like anything else. The panel used to refuse those outright; it no longer does. Worth knowing: those suffixes are not on the Public Suffix List, so their weekly issuance limit is shared with everyone using the service, and a request there can fail with "too many certificates already issued" for reasons nothing on this server caused.
- A name that cannot be validated is still refused, with a reason — the check is a real ACME dry run, not a guess from the suffix. `self_signed` remains available on any name, including `.test` and internal hostnames Let's Encrypt could never reach.
- All `reason` strings are localised.

---

### POST `/applications/{application}/certificate`
**Permission:** `app_domain` (manage)

Issue a new certificate (queued, `202`) or upload an existing one (synchronous, `201`).

**Request:**
```json
{"type": "letsencrypt", "force": false}
```
OR
```json
{"type": "custom", "certificate": "-----BEGIN CERTIFICATE-----…", "private_key": "-----BEGIN PRIVATE KEY-----…", "chain": "-----BEGIN CERTIFICATE-----…"}
```

The upload fields are `certificate` and `private_key` — not `cert`/`key`. Both are required when `type` is `custom`, must start with `-----BEGIN`, and are checked as a **pair before anything is written**: a mismatched certificate and key are accepted by the filesystem, fail the web server's config test, and take the site down over a copy-paste. `chain` is optional.

There is **no `domains` field**. A Let's Encrypt request covers the application's own domains — add or remove domains first, then issue. See `certifiable` and `missing_domains` on the certificate object for which of them made it in.

`force` skips the reachability dry run. Don't default it on: the dry run is what stops a doomed attempt from spending one of the five authorisation failures per hour Let's Encrypt allows. The one legitimate use is a server behind NAT whose public address does not answer to itself — the dry run fails there, but the real challenge arrives from outside and would succeed.

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

Remove the certificate and its redirect rule. The application-native canonical URL is switched back to HTTP before the non-TLS vhost is applied. Physical certificate cleanup happens only after HTTP is live.

If URL/vhost transition fails, the previous certificate status, canonical URL, and vhost are restored best-effort. If later physical cleanup fails, the API returns `500` with the normal server-operation reference and retains the certificate row as `pending`; retry **this same DELETE** to finish cleanup. A retry after success is also safe and returns `204` when no certificate remains.

**Response `204`:** `null`

---

## Application — Deployment

### GET `/applications/{application}/deployments`
**Permission:** `app_deployment` (view)

Newest first.

```json
{"deployments": [{
  "id": 12,
  "status": "succeeded", "status_title": "Succeeded", "in_flight": false,
  "trigger": "manual", "trigger_title": "Manual deploy",
  "user": {"id": 1, "username": "admin"},
  "branch": "main",
  "commit_hash": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0",
  "commit_short": "a1b2c3d",
  "commit_message": "Fix payment redirect",
  "commit_author": "Priya Nair",
  "steps": ["init", "fetch", "checkout", "set_ownership", "script"],
  "failed_step": null,
  "reference": null,
  "duration": 90,
  "started_at": "28-07-2026 11:00:00",
  "finished_at": "28-07-2026 11:01:30",
  "created_at": "28-07-2026 11:00:00", "created_at_human": "3 days ago"
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

**`status` is `queued` · `running` · `succeeded` · `failed`.** There is no `completed` and no `pending`. Rather than hardcoding which of those are terminal, poll on **`in_flight`** — it is the backend's own answer to "is this still going", so a new status added later cannot silently break the list's polling.

**`output` is not in the list.** It appears only on the single-deployment endpoint below; fifty deploys each carrying a full build log is a response nobody asked for.

`commit_hash` is the full 40 characters, `commit_short` the one people recognise — the old `commit` key does not exist. `commit_author` is the commit's own author, which is not the `user` who pressed deploy.

`user` is **null for a webhook deploy** — nobody pressed anything, and inventing an actor would be a lie. Render it as "System". On `POST …/deployments` and `…/redeploy` the key is **absent entirely** (the relation is not loaded there); it is present on this list and on the single-deployment view.

`steps` accumulates step names in the order they completed — `init`, `fetch`, `checkout`, `set_ownership`, `script` — so the UI can show which stage a running deploy reached instead of a bare spinner. On a failure, `failed_step` names the one that broke and `reference` is the id to quote to support; the technical detail lives in the server-ops log under that id, never in the response.

`duration` is whole seconds, and null until the deploy has both started and finished.

---

### POST `/applications/{application}/deployments`
**Permission:** `app_deployment` (manage)

Start a deploy.

**Response `202`:** `{"deployment": {"id": 13, "status": "queued", "in_flight": true, …}}`

---

### GET `/applications/{application}/deployments/{deployment}`
**Permission:** `app_deployment` (view)

The same deployment object as the list, plus **`output`** — the full build log, with credentials redacted. Each command appears as `$ <step>` followed by what it printed.

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
{"webhook_providers": [
  {"name": "github", "title": "GitHub", "secret_source": "generate", "instructions": "…"},
  {"name": "gitlab", "title": "GitLab", "secret_source": "either", "instructions": "…"},
  {"name": "bitbucket", "title": "Bitbucket", "secret_source": "generate", "instructions": "…"}
]}
```

**This is a list, not an object keyed by provider** — key it yourself on `name` if you need lookup. `title` and `instructions` are already localized; render `instructions` as the setup help next to the URL, rather than shipping three hardcoded sets of steps in the frontend.

**`secret_source` decides what the secret field does**, and the three values are not interchangeable:

| value | provider | what the UI must do |
|---|---|---|
| `generate` | GitHub, Bitbucket | Leave `secret` out. The panel mints one (64 hex chars) and returns it in full — show a copy button. |
| `either` | GitLab | Offer the choice, and **make the signing token the default**. GitLab alone mints a signing token itself, and the panel cannot generate one; leaving `secret` out does not fail, it silently falls back to GitLab's *legacy plaintext* secret. Read `webhook.verification` from the response to see which check ended up in force — `signature` (strong) or `token` (plaintext shared value). |
| `paste` | — | Defined in the contract but returned by no provider today. The provider would mint the secret and `secret` would be required. Handle it if you switch on this field exhaustively. |

---

### PUT `/applications/{application}/webhook`
**Permission:** `app_deployment` (manage)

Turn deploy-on-push on or off. **This endpoint does not set the repository or branch** — those live on the application itself; sending them here does nothing.

**Request:**
```json
{"enabled": true, "provider": "github", "secret": "…", "rotate": false}
```

| field | rule |
|---|---|
| `enabled` | **required**, boolean. Sending only `{"provider": …}` is a `422`. |
| `provider` | required when `enabled` is true; nullable otherwise. One of the `name` values from `GET /webhook-providers`. Required outright for a public repository, which has no connected account to infer it from. |
| `secret` | optional, `min:16`, `max:255`. Omit it and the panel generates one — but only on the first configure. On a later call an existing stored secret is **kept**, not regenerated, so a plain on/off toggle never invalidates a webhook the user has already pasted into their provider. |
| `rotate` | optional boolean. The only way to replace a stored secret without pasting one — mints a new value and invalidates the old, for a secret that leaked. Without it, `enabled: true` on an already-configured webhook is a no-op on the secret. |

The `min:16` on `secret` is enforced with its own message: a four-character "secret" is findable in a few thousand requests, so the field rejects it rather than accepting a value that only looks like protection.

**Response `200`:** the **full application object** (the same shape as `GET /applications/{id}`), not a webhook-only payload. Read the nested `webhook` object from it:

```json
{"application": {"…": "…", "webhook": {
  "enabled": true, "provider": "github",
  "url": "https://panel.example.com/api/webhooks/deploy/abc123",
  "secret": "…", "verification": "signature",
  "last_delivered_at": null, "last_delivered_at_human": null
}}}
```

There is no `webhook_enabled` or `webhook_identifier` key in the response — the identifier is already baked into `webhook.url`, which is assembled server-side so the frontend never builds the path or gets the host wrong. Show `webhook.url` as the callback URL the user pastes into the provider. `last_delivered_at` is how you tell "configured" from "actually working"; a webhook that has never fired is worth surfacing.

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
  "exists": true,
  "path": "/home/siteowner/shop.example.com/.env",
  "framework": "laravel", "framework_title": "Laravel",
  "requires_restart": false,
  "requires_apply": true,
  "raw": "APP_NAME=Shop\nDB_HOST=127.0.0.1\n…",
  "variables": [
    {"key": "APP_NAME", "value": "Shop", "secret": false},
    {"key": "DB_PASSWORD", "value": null, "secret": true}
  ],
  "checks": [
    {"code": "app_debug_enabled", "severity": "danger", "key": "APP_DEBUG", "value": "true", "title": "…", "detail": "…"}
  ],
  "backups": ["2026-07-28-141530", "2026-07-27-093000"]
}}
```

All of this comes from **one read** of the file. A raw endpoint plus a parsed endpoint plus a checks endpoint would read it three times and can return three different answers if someone saves in between.

`exists: false` is a normal state (nothing written yet), not an error — `raw` is then `""` and `checks` is empty.

**A secret's `value` is `null`, not a masked string.** Don't render dots from the API; render them from `secret: true`.

**`requires_restart` and `requires_apply` are the two ways a save can appear to do nothing**, answered up front so the button can say what it will actually do:
- `requires_restart` — the application runs a process of its own, which is holding the old values in memory.
- `requires_apply` — a compiled config cache exists (`bootstrap/cache/config.php` on Laravel/Statamic), which is read *instead of* the file. Editing `.env` with one present changes nothing at all until it is rebuilt.

`framework` is one of `laravel`, `craft`, `statamic`, `nextjs`, `nuxt`, `node`, `unknown` — detected from what is on disk, not from the site type.

`checks` are lint results on the file's contents, each with a `code`, a `severity`, the offending `key`/`value`, and a localised `title`/`detail`.

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
  {"name": "seo-pack", "type": "dir", "size": 4096, "size_human": "4 KB",
   "modified_at": "27-07-2026 10:00:00", "modified_at_human": "2 weeks ago",
   "mode": "drwxr-xr-x", "owner": "siteowner", "group": "siteowner",
   "link_target": null, "link_broken": null},
  {"name": "README.md", "type": "file", "size": 4096, "size_human": "4 KB",
   "modified_at": "25-07-2026 14:30:00", "modified_at_human": "3 weeks ago",
   "mode": "-rw-r--r--", "owner": "siteowner", "group": "siteowner",
   "link_target": null, "link_broken": null},
  {"name": "uploads", "type": "symlink", "size": 0, "size_human": "0 B",
   "modified_at": "25-07-2026 14:30:00", "modified_at_human": "3 weeks ago",
   "mode": null, "owner": null, "group": null,
   "link_target": "../shared/uploads", "link_broken": false}
]}
```

`type` is `file`, `dir` or `symlink` — note `dir`, not `directory`.

`mode` is the full `ls -l` string (type char + permission bits), **not** an octal — `PUT …/files/permissions` takes an octal, so don't echo this value back at it. `owner` and `group` are separate fields, not part of `mode`.

On a **symlink**, `mode`/`owner`/`group` are `null` — a link's own mode is always `lrwxrwxrwx` and its ownership says nothing about the target, so a constant is not shown. `link_target` is where it points, left exactly as written (a relative target stays relative), and `link_broken` is `true` when the target does not exist, is a loop, or could not be read. Both are `null` on non-symlinks.

---

### GET `/applications/{application}/files/search`
**Permission:** `app_file` (view) | **Throttle:** 10/min

Recursive filename search.

**Query:** `?q=config&path=/`

`q` is **required** (1–255 chars) — the parameter is `q`, not `search`. `path` is optional and defaults to the site root; it scopes the search to a subtree. Glob metacharacters in `q` are escaped, so the query matches literally rather than as a wildcard pattern.

**Response `200`:**
```json
{"path": "/", "query": "config", "files": [
  {"path": "/wp-config.php", "name": "wp-config.php", "type": "file",
   "size": 4096, "size_human": "4 KB",
   "modified_at": "25-07-2026 14:30:00", "modified_at_human": "3 weeks ago",
   "mode": "-rw-r--r--", "owner": "siteowner", "group": "siteowner",
   "link_target": null, "link_broken": null}
], "truncated": false}
```

Each entry is the **same shape as a browse entry** (see `GET …/files` above) with one extra field, `path`, holding the location relative to the site root — the browse listing has no `path` because everything in it sits in the directory you asked for.

`truncated: true` means the result was capped at 200 matches; narrow the query or the `path`.

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

Upload one file in a single request. **Capped at 50 MB** — the whole body is
buffered through PHP memory. For anything larger use the resumable endpoints
below; there is no size limit there.

**Body:** `file` (binary), `path` (destination directory, e.g. `wp-content`)

**Response `200`:** `{"uploaded": true}`

---

### GET `/applications/{application}/files/uploads/space`
**Permission:** `app_file` (view)

Free space before starting a large upload, so the UI can refuse early rather
than after the user has waited.

---

### POST `/applications/{application}/files/uploads`
**Permission:** `app_file` (manage) | **Throttle:** 30/min

Opens a resumable upload and returns its id. Validates up front that the
destination directory exists and that the disk has room.

**Request:** `{"path": "wp-content/big-backup.zip"}`

**Response `201`:** `{"upload": {"id": "…32 hex…"}}`

---

### PUT `/applications/{application}/files/uploads/{uploadId}`
**Permission:** `app_file` (manage) | **Throttle:** 1200/min | **Content-Type:** raw body

Appends one chunk. **The body is the raw bytes, not multipart** — that halves
the disk traffic of an upload on a box that is also serving customer sites.

Bounds: one chunk must fit `client_max_body_size` (**64 MB**); the reference
client sends **8 MB**. The throttle is per chunk, not per file, so a large
upload is legitimately thousands of requests.

**There is no file-size limit.** The only bound is free disk: a chunk is
refused if writing it would leave less than 5 GB or 10% of the disk free,
whichever is larger.

---

### GET `/applications/{application}/files/uploads/{uploadId}`
**Permission:** `app_file` (view) | **Throttle:** 60/min

Bytes received so far — **this is the resume offset.** Restart from it after a
dropped connection; there is no separate session to reconcile.

---

### POST `/applications/{application}/files/uploads/{uploadId}/finalize`
**Permission:** `app_file` (manage) | **Throttle:** 30/min

Moves the assembled file into place. A same-filesystem rename, so it is atomic
and instant — a half-finished upload is never visible at the target path.

---

### DELETE `/applications/{application}/files/uploads/{uploadId}`
**Permission:** `app_file` (manage) | **Throttle:** 30/min

Abandons an upload and removes its part file.

---

### GET `/applications/{application}/files/download`
**Permission:** `app_file` (view) | **Throttle:** 20/min

Download a file. **Streamed, with no size limit** (changed 11-08-2026 — it was
previously capped at 5 MB).

The response is a stream, not a buffered body: read it as a blob, not as text.
`Content-Length` carries the real size so a progress bar is possible, and
`Content-Disposition` includes an RFC 6266 `filename*` alongside the plain
`filename`.

**Query:** `?path=wp-content/seo-pack.zip`

---

### POST `/applications/{application}/files/extract`
**Permission:** `app_file` (manage) | **Throttle:** 5/min

Extract a **`.zip`, `.tar.gz` or `.tgz`** archive already in the site. Guarded
against zip bombs: refused above 250 MB uncompressed or 10,000 entries, and
any entry that is a symlink or escapes the destination.

**Request:** `{"path": "plugin.zip", "target": "wp-content/plugins"}`

**Response `200`:** `{"extracted": true}`

---

### POST `/applications/{application}/files/directories`
**Permission:** `app_file` (manage) | **Throttle:** 20/min

Create one directory. The parent must already exist.

**Request:** `{"path": "wp-content/uploads/2026"}`

**Response `200`:** `{"created": true}`

---

## Bulk file operations

`rename`, `copy`, `permissions`, `compress` and `DELETE /files` each accept
**either** a single `path` **or** a `paths[]` selection. The single form is
unchanged and still supported.

The two are not the same operation:

| | single `path` | `paths[]` |
|---|---|---|
| Missing file | **404** | `200` with a `failed` entry |
| Result | `{"deleted": true}` | per-path `succeeded[]` / `failed[]` |

**Bulk response:**

```json
{"deleted": false,
 "succeeded": ["cache/a.txt", "cache/b.txt"],
 "failed": [{"path": "cache/gone.txt", "reason": "not_found"}]}
```

`reason` is `not_found`, `exists` (something is already at the destination) or
`failed`. A batch can partly succeed — show the failures rather than treating
the call as failed.

**Limits:** at most **250** paths per request (`422` above that — an argument
vector has a kernel limit, and crossing it would fail with some paths already
handled). Every entry is validated individually, so one bad path rejects the
whole request with `422`.

---

### PUT `/applications/{application}/files/rename`
**Permission:** `app_file` (manage) | **Throttle:** 20/min

Rename or move one path; move a selection into a directory.

**Single:** `{"path": "wp-content/old-name", "target": "wp-content/new-name"}`
**Bulk:** `{"paths": ["cache/a.txt", "cache/b.txt"], "target_directory": "keep"}`

A destination that is already occupied is refused, never overwritten.

**Response `200`:** `{"renamed": true, "succeeded": […], "failed": […]}`

---

### POST `/applications/{application}/files/copy`
**Permission:** `app_file` (manage) | **Throttle:** 10/min

**Single:** `{"path": "wp-config.php", "target": "wp-config.php.bak"}`
**Bulk:** `{"paths": ["cache/a.txt", "cache/sub"], "target_directory": "keep"}`

**Response `200`:** `{"copied": true, "succeeded": […], "failed": […]}`

---

### POST `/applications/{application}/files/compress`
**Permission:** `app_file` (manage) | **Throttle:** 10/min

**The target's extension chooses the format:** `.zip`, `.tar.gz` or `.tgz`.
Anything else is `422` — the extension is what selects the command, so an
unrecognised one has nothing to run rather than a default to guess at. Same set
`POST …/files/extract` accepts, from the same function, so the panel can always
open what it writes.

**Single:** `{"path": "wp-content", "target": "wp-content-backup.tar.gz"}`
**Bulk:** `{"paths": ["cache/a.txt", "cache/b.txt"], "target": "cache/bundle.zip"}`

**Prefer `.tar.gz` for site content.** ZIP does not carry Unix permissions, so
a folder zipped and later extracted comes back with whatever modes the
extractor decided — a `wp-config.php` at `0600` does not stay `0600`. `tar.gz`
preserves mode, ownership and symlinks, and compresses better across many small
files. Offer `.zip` for portability, and default to `.tar.gz` for "keep a copy
before I change this".

Bulk sources must all sit in **the same folder** (`422` otherwise): the command
runs from that folder so the archive holds bare names rather than the server's
directory layout, and there is no folder to run from once the sources are
spread across the tree. Disable the button when a selection spans folders.

**Response `200`:** `{"compressed": true}`

---

### PUT `/applications/{application}/files/permissions`
**Permission:** `app_file` (manage) | **Throttle:** 20/min

Change the mode. **Exactly three octal digits** — `644`, not `0644`. A fourth
digit (setuid/setgid/sticky) is refused with `422`.

**Single:** `{"path": "wp-config.php", "mode": "644"}`
**Bulk:** `{"paths": ["cache/a.txt", "cache/b.txt"], "mode": "600"}`

**Response `200`:** `{"chmoded": true, "succeeded": […], "failed": […]}`

---

### DELETE `/applications/{application}/files`
**Permission:** `app_file` (manage) | **Throttle:** 10/min

**Single:** `{"path": "wp-content/old-plugin", "confirm": true}`
**Bulk:** `{"paths": [...], "confirm": true, "count": <paths.length>}`

`confirm` is required either way. For a selection, `count` must equal the
number of paths or the request is refused with `422` — a stale selection is
the realistic bulk-delete accident, and `confirm` cannot catch it because it
is true either way. Send `paths.length`; do not hardcode it.

**Deleting moves to the trash by default.** Add `"permanent": true` to destroy
instead. Omitting it means recoverable, which is the safer reading of an
ambiguous request — so existing clients gain a safety net without changing.

Offer both in the UI. Permanent has to stay reachable: someone deleting 40 GB
to free disk space and seeing nothing freed would rightly call that a bug.

A trashed selection keeps one batch id, so twelve things deleted together are
restored together rather than one at a time.

**Response `200`:** `{"deleted": true, "succeeded": […], "failed": […]}`

---

### GET `/applications/{application}/files/trash`
**Permission:** `app_file` (view) | **Throttle:** 60/min

What is recoverable, newest first. An empty list is a normal answer.

```json
{"trash": [
  {"batch": "20260813-104500", "path": "wp-content/old-plugin", "deleted_at": "13-08-2026 10:45:00", "size": 48234496, "size_human": "46 MB"}
], "total_size": 48234496, "total_size_human": "46 MB", "retention_days": 7}
```

`path` is where it came from, which is also where it goes back to — `plugin.php`
alone would not say which one it was. Only the top of a deleted tree is listed:
a deleted directory is one thing the user deleted, not four hundred.

`size` is the reclaimable on-disk footprint of that entry (directories included),
not its inode size. `size` and `size_human` are `null` if that entry cannot be
read; `total_size` and `total_size_human` are also `null` when any entry is
unreadable, rather than claiming an incomplete total. `retention_days` is the
operator-configurable automatic-retention window — use this value, never a
frontend constant.

Everything lives above the document root, so nothing here is reachable over
HTTP — a deleted `wp-config.php` still holds live credentials.

---

### POST `/applications/{application}/files/trash/restore`
**Permission:** `app_file` (manage) | **Throttle:** 30/min

**Single-path request:** `{"batch": "20260813-104500", "path": "wp-content/old-plugin"}`

**Restore-all request:** `{"batch": "20260813-104500"}`

With `path`, restores only that path. Without it, restores every top-level
entry in the batch independently. An occupied destination never blocks the
other entries in its batch.

`batch` must be `YYYYMMDD-HHMMSS`. It is half a filesystem path, so anything
else is refused rather than sanitised.

**Response `200`:**
```json
{
  "restored": false,
  "succeeded": ["wp-content/old-plugin"],
  "failed": [{"path": "wp-config.php.bak", "reason": "exists"}],
  "trash": [],
  "total_size": 0,
  "total_size_human": "0 B",
  "retention_days": 7
}
```

`restored` is true only when every requested item was restored. For a
single-path request, an occupied destination remains a `422`; batch restore
instead reports it in `failed` and continues. `reason` is `exists`,
`not_found`, or `failed`. `trash`, totals, and `retention_days` use the same
shape as `GET .../files/trash`.

---

### DELETE `/applications/{application}/files/trash`
**Permission:** `app_file` (manage) | **Throttle:** 10/min

**Request:** `{"confirm": true}` — everything, or `{"batch": "…", "confirm": true}`
for one batch.

**This is the only unrecoverable action the file manager has, deliberately: it
is how the disk space comes back.** Trash is swept automatically after
`SERVER_TRASH_RETENTION_DAYS` (default 7).

**Response `200`:** the updated `{"trash": [...]}`.

---

## Application — PHP Settings

### GET `/applications/{application}/php`
**Permission:** `app_php` (view)

`403` for non-PHP site types.

**The editable values live under `settings`, not at the top level.** There is no `opcache_*` field on this endpoint.

```json
{"php": {
  "application_id": 1,
  "php_version": "8.4",
  "available_versions": ["8.1", "8.2", "8.3", "8.4"],

  "isolated": true,
  "isolated_at": "25-07-2026 14:30:00",
  "isolation_supported": true,
  "runs_as": "siteowner",
  "managed": true,

  "settings": {
    "memory_limit": "256M",
    "upload_max_filesize": "64M",
    "post_max_size": "128M",
    "max_execution_time": 120,
    "max_input_time": 60,
    "max_input_vars": 3000,
    "session_gc_maxlifetime": 1440,
    "pm_type": "ondemand",
    "pm_max_children": 6,
    "pm_max_requests": 500,
    "open_basedir_enabled": true,
    "open_basedir_paths": "/var/www/shop/extra",
    "disable_functions": "exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec",
    "allow_url_fopen": true,
    "php_timezone": "UTC",
    "auto_prepend_file": null,
    "additional_directives": null
  },

  "overridden": {
    "memory_limit": true, "upload_max_filesize": false, "post_max_size": false,
    "max_execution_time": true, "max_input_time": false, "max_input_vars": true,
    "session_gc_maxlifetime": false, "pm_type": false, "pm_max_children": false,
    "pm_max_requests": false, "open_basedir_enabled": true, "open_basedir_paths": true,
    "disable_functions": true, "allow_url_fopen": false, "php_timezone": false,
    "auto_prepend_file": false, "additional_directives": false
  },

  "presets": [
    {"key": "low", "title": "…", "description": "…", "pm_type": "ondemand", "pm_max_children": 2},
    {"key": "balanced", "title": "…", "description": "…", "pm_type": "ondemand", "pm_max_children": 6},
    {"key": "high", "title": "…", "description": "…", "pm_type": "dynamic", "pm_max_children": 12}
  ],

  "memory": {
    "total": 8360124416, "committed": 2147483648, "available": 6212640768,
    "over_committed": false, "sites": 4, "this_site": 1610612736
  },

  "open_basedir_effective": "/var/www/shop:/var/www/shop/.sessions:/tmp:/var/www/shop/extra",
  "open_basedir_live": "/var/www/shop:/var/www/shop/.sessions:/tmp",
  "open_basedir_recommended": "/var/www/shop:/var/www/shop/.sessions:/tmp",

  "suggested_disable_functions": "exec,passthru,…",
  "disable_functions_presets": [
    {"key": "safe",   "title": "Recommended", "description": "…", "functions": "exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec"},
    {"key": "strict", "title": "Strict",      "description": "…", "functions": "getmyuid,passthru,shell_exec,dl,exec,system,…"}
  ]
}}
```

**`settings` is always the *effective* value** — the user's override where one exists, the panel default otherwise. `overridden` is the parallel map saying which is which, so you can render a per-field "Reset to default" control instead of guessing. Same keys, same order, one boolean each.

**`isolated` gates the entire screen.** Every value here is only enforceable once the site has a pool of its own; on a shared pool `memory_limit` is shared too. `isolated_at` is when that happened; `isolation_supported` is false where the web server has no FPM at all (OpenLiteSpeed runs LSAPI), so hide the isolate action rather than offering one that cannot work. `runs_as` is the Linux user the site's PHP executes as — its own system user once isolated, otherwise the server-wide web user (`www-data`).

The shared `www-data` pool is **no longer a choice**: asking for it answers `405`. It survives only as a state for sites migrated from an older panel and for OpenLiteSpeed.

**`managed: false` means the pool file on disk no longer matches what the panel would write** — someone edited it by hand. Warn *before* the user presses save, not after their edits are gone.

**`open_basedir` has three answers on purpose, and they are all different questions.** `additional_directives` is appended to the pool config raw, so a directive a user writes there lands *after* the panel's and wins.

| field | question it answers |
|---|---|
| `open_basedir_effective` | what the panel would write from the stored settings |
| `open_basedir_live` | what the pool file on disk actually says right now — null when the site has no pool, or its pool sets nothing. Differs from `effective` after a hand edit or an override via `additional_directives` |
| `open_basedir_recommended` | what switching it on with no additions would give — the value to offer when it is off, so the screen proposes a path list instead of asking the user to invent one |

Paths the user adds are **appended** to a base of the application root plus that site's own session directory plus `/tmp`. A server-wide session directory (`/var/lib/php/sessions`, which a migrated commercial panel leaves behind) is deliberately never included: it would let every site read every other site's sessions.

`pm_type` is `ondemand` / `dynamic` / `static`, and `presets` exists so nobody has to reason about worker counts from first principles — three named starting points, each carrying the `pm_type` and `pm_max_children` it implies. `memory` is the budget in **bytes**, recalculated against `/proc` on every request (a VPS can be resized underneath a cached figure); `this_site` is what the current settings commit, so the screen can show the cost of a change before it is saved and flag `over_committed`.

`suggested_disable_functions` is the `safe` list repeated as a flat string,
kept so older clients keep working. Prefer `disable_functions_presets` — a third preset should
not need a frontend change. Titles and descriptions in both `presets` arrays are already localised by `Accept-Language`.

`suggested_disable_functions` is the `safe` list repeated as a flat string,
kept so older clients keep working. Prefer the array — a third preset should
not need a frontend change.

---

### PUT `/applications/{application}/php`
**Permission:** `app_php` (manage) | **Throttle:** 10/min

Update PHP version and/or pool settings.

**Request:**
```json
{"php_version": "8.4", "memory_limit": "512M", "max_execution_time": 300, "upload_max_filesize": "128M"}
```

**Response `200`:** `{"php": {...updated...}}`

**Send `null` to clear an override and fall back to the server default.** Every
field that can be overridden accepts it — including `pm_type`,
`pm_max_children`, `pm_max_requests` and `allow_url_fopen`, which until
2026-08-13 could be set but never cleared, so "reset to default" was only ever
half a feature.

One exception, and it is not an oversight: **`open_basedir_enabled` is a plain
boolean** with a column default of `false`. It has no override to clear, so
"reset" for that one is `false`, not `null`.

Omitting a field leaves it as it is; sending `null` is what clears it. The two
are different requests.

`GET .../php` reports open_basedir three ways, and they are allowed to disagree:

| field | meaning |
|---|---|
| `open_basedir_effective` | what the panel would write from the saved settings. `null` when off. |
| `open_basedir_live` | what the pool file on disk **actually** sets. `null` when the site has no pool, or its pool sets nothing. |
| `open_basedir_recommended` | what switching it on with no additions would give — show this as the suggested value when it is off. |

On a **migrated server**, the first time the panel takes ownership of a site's pool (`POST .../php/isolate` or `php artisan php:isolate-all`) it adopts whatever `open_basedir` was already there: the old panel's paths are kept as `open_basedir_paths` and the setting is switched on. A server-wide session directory (`/var/lib/php/sessions` and friends) is deliberately **not** carried over — importing it would let that site read every other site's sessions, and the site's own session directory is in the base paths already. The command prints what it kept and what it dropped, per site.

`live` differs from `effective` when someone hand-edited the pool file, or set their own `open_basedir` through `additional_directives` (which is appended raw and wins, since FPM takes the last of a repeated key). When they differ, show `live` — that is what PHP is enforcing — and `managed` will also be `false`.

`open_basedir_paths` holds **additional** directories, one per line (`:` and `,` also accepted). The app root, the site's own session directory and `/tmp` are always included and cannot be removed — without them the site cannot read its own code or keep anyone logged in. Read `open_basedir_effective` from `GET .../php` to show the exact value the pool file will contain.

**`422` on `open_basedir_paths`** for a relative path, a path containing `..`, or `/` — the last because it would leave the setting switched on while enforcing nothing.

**`422` on `settings`** when the site has `isolated: false` and `isolation_supported: true`. Every limit on this form is enforced by the pool file, so without a pool they would be stored and never applied. Offer the isolate action instead of a save.

`php_version` is exempt — it is carried by the vhost and can be changed either way.

---

### POST `/applications/{application}/php/isolate`
**Permission:** `app_php` (manage) | **Throttle:** 5/min

Give this site its own PHP-FPM pool running as its own user.

**This is a repair, not a mode.** Every site the panel provisions already gets its own pool at creation. The only sites that need this are ones the panel did not create: adopted from another panel, made before pool isolation shipped, or left behind by a failed pool step. Surface it only when `isolated: false` **and** `isolation_supported: true`.

**Response `200`:** `{"php": {"isolated": true, "isolated_at": "29-07-2026 10:00:00", …}}`

`422` if already isolated or if the web server is OpenLiteSpeed (OLS runs LSPHP and has no pools — `isolation_supported` is `false` there and no isolation UI should show at all).

Server-side, `php artisan php:isolate-all` converts every remaining shared site in one pass.

---

### ~~DELETE `/applications/{application}/php/isolate`~~ — removed

Returns **`405`**. There is no supported way back onto the shared pool: it means running as the web server's own account again, which lets one compromised site read every other site's `.env`. Remove any "un-isolate" / "back to shared pool" control.

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
  "status": "active", "php_version": "8.4",
  "is_staging": true, "production_application_id": 1,
  "system_user": {"id": 1, "username": "siteowner"},
  "created_at": "28-07-2026 09:00:00", "created_at_human": "3 days ago"
}}
```

A staging site is **just another application row** — the full application object, with `is_staging: true` and `production_application_id` pointing back. `staging: null` when none exists.

⚠️ The production site's own `has_staging` flag is documented on the application resource but **no endpoint currently loads the relation that produces it**, so the key never appears in practice. Decide "create staging vs view staging" from this endpoint returning null or an object, not from `has_staging`.

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

**Request:** `{"mode": "files|full"}`

- `files` — replace production files only; production uploads, caches, VCS metadata, build artefacts, logs and panel bookkeeping are excluded.
- `full` — replace production files and database. The panel writes a private production database dump before making changes.

Before either mode changes production, the panel creates a temporary private file snapshot. If the push fails, it restores the files (and the database for `full`) before bringing production back online. A successful push or successful recovery removes the temporary file snapshot; the pre-push SQL dump is retained.

**Response `200`:** `{"application": {...updated production record...}}`

**Recovery failure `500`:**
```json
{"message":"The staging push failed and production could not be restored. The site remains disabled. Quote the reference to support.","code":"staging_rollback_failed","reference":"…"}
```

When recovery itself fails, production deliberately remains disabled and the private recovery files are preserved. The `reference` correlates with the server-operations log.

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

## Application — 8G Firewall

The Perishable Press 8G ruleset (v1.5), applied as web-server config — no daemon and no package. Six independently switchable categories, two modes, and a per-app exceptions/custom-rules list. Permission `app_firewall`; the screen is titled **8G Firewall**.

### GET `/waf-options`
**Permission:** `app_firewall` (view)

The six categories and two modes, plus whether this server can enforce them at all.

```json
{
  "waf_supported": true,
  "web_server": "nginx",
  "waf_categories": [
    {"value": "query_string", "title": "Bad search terms", "description": "Blocks requests whose search terms carry SQL, script or file-path tricks…"},
    {"value": "request_uri", "title": "Bad web addresses", "description": "…"},
    {"value": "user_agent", "title": "Bad visitors", "description": "…"},
    {"value": "referrer", "title": "Bad links", "description": "…"},
    {"value": "cookie", "title": "Bad cookies", "description": "…"},
    {"value": "method", "title": "Bad request types", "description": "…"}
  ],
  "waf_modes": [
    {"value": "detect", "title": "Just watch, don't block"},
    {"value": "enforce", "title": "Actually block"}
  ]
}
```

The six `value`s are the complete set — there is no `sql_injection`, `xss`, `spam` or `bad_js` category. `title` and `description` are localized; render them rather than mapping the values yourself.

**`waf_supported` is `false` on OpenLiteSpeed**, where the rules are not yet implemented. It is server-wide, because one server runs one web server.

You should not normally need it: on such a server `app_firewall` is **dropped from the application's feature list**, so it never appears in `GET /permissions?level=application&application_id=…` and the sidebar simply has no 8G Firewall item — the same hide-rather-than-grey treatment as a Deployment screen on a WordPress site. The endpoints below answer **`404`** there too, via the same list. `waf_supported` is for the case where you want to explain the absence rather than just omit it.

---

### GET `/applications/{application}/waf`
**Permission:** `app_firewall` (view)

Returns the application resource. The firewall fields on it:

```json
{"application": {
  "id": 1,
  "waf_supported": true,
  "waf_enabled": true,
  "waf_mode": "detect", "waf_mode_title": "Just watch, don't block",
  "waf_categories": ["query_string", "request_uri", "user_agent", "referrer", "cookie", "method"],
  "waf_exceptions": ["/wp-admin/admin-ajax.php"],
  "waf_custom_rules": ["/xmlrpc.php"]
}}
```

`waf_categories` is a **flat array of enabled values**, never an object of booleans. A stored `null` is resolved here to all six, so the frontend never has to know that null and the full list mean the same thing.

`waf_exceptions` and `waf_custom_rules` are **arrays of plain strings** (max 50 each, 1–255 chars), not objects — an exception is matched against the request URI, query string and user agent. Both use `whenLoaded`, so they are **absent** rather than empty when the relation was not loaded.

---

### PUT `/applications/{application}/waf`
**Permission:** `app_firewall` (manage) | **Throttle:** 10/min

**Request:**
```json
{
  "enabled": true,
  "mode": "detect",
  "categories": ["query_string", "request_uri", "user_agent", "referrer", "cookie", "method"],
  "exceptions": ["/wp-admin/admin-ajax.php"],
  "custom_rules": ["/xmlrpc.php"]
}
```

`enabled` and `mode` are **required**. `categories`, `exceptions` and `custom_rules` are optional, and **omitting one leaves the stored value alone** — it does not reset it. That matters: absent once meant "all six on", which silently re-enabled the category a user had switched off to fix a false positive. Send an empty array to clear.

**Response `200`:** `{"application": {...}}`

**Response `404`** — the web server cannot enforce the ruleset (currently OpenLiteSpeed), so `app_firewall` is not among the application's features and the route does not exist for it. The screen should already be hidden; this is the backstop.

**Response `422`** — `enabled` — the same refusal from the service layer. Not reachable through this route while the 404 above applies, and kept because the manager is also called from the console and from sync.

**Mode matters more than it looks.** `detect` blocks nothing and logs what *would* have been blocked to a separate file, exposed as the `waf_detect` log key (see Application — Logs). It is listed **only while the mode is `detect`**, because only that mode writes it. The intended flow is detect → read the log → add exceptions → enforce; a UI that pushes straight to `enforce` skips the step that makes this safe to turn on.

Applying is atomic: the config is written, tested and reloaded, and rolled back on failure — including the rules, which are not persisted until the config test passes.

---

## Application — Per-site Fail2ban

Per-application fail2ban watches one site's own access log. The jail and filter are raw INI files written verbatim to `/etc/fail2ban/{jail,filter}.d/<app-slug>.conf` and reloaded into the daemon. Any feature fail2ban's INI supports (custom regex, multiple logpaths, additional actions) is reachable from the form, not just the structured `maxretry/findtime/bantime` of the previous implementation.

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
  "jail_name": "shop",
  "jail_content": "[shop]\nenabled  = true\nport     = http,https\nfilter   = shop\nlogpath  = /var/log/nginx/shop.access.log\nmaxretry = 3\nbantime  = 3600\nfindtime = 600\n",
  "filter_content": "[shop]\nfailregex = ^<HOST> .* \"(POST|PUT|DELETE) .*wp-login.php\n           ^<HOST> .* \"(POST|PUT|DELETE) .*xmlrpc.php\n           ^<HOST> .* \"(POST|PUT|DELETE) .*wp-admin.*\nignoreregex =\n"
}, "jail_template": "[shop]\nenabled  = true\nport     = http,https\n...", "filter_template": "[shop]\nfailregex = ^<HOST> .* \"(POST|PUT|DELETE) .*wp-login.php\n..."}
```

The jail file template:

```ini
[{slug}]
enabled  = true
port     = http,https
filter   = {slug}
logpath  = {webroot}/logs/access.log
maxretry = 3
bantime  = 3600
findtime = 600
```

The filter file template:

```ini
[{slug}]
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
{"jail_config_content": "[shop]\nenabled  = true\n...\n", "filter_config_content": "[shop]\nfailregex = ^<HOST>\n...\n"}
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

Read a log source — the **last** N lines of it.

**Query:** `?lines=200&grep=error` (lines: default 200, max 5000; grep: case-insensitive literal filter)

**Response `200`:**
```json
{"log": {
  "key": "access", "label": "Access Log", "kind": "file", "exists": true,
  "lines": ["192.168.1.1 - - [29/Jul/2026:11:00:00 +0000] \"GET / HTTP/1.1\" 200 1234"],
  "truncated": true,
  "search_window_capped": false
}}
```

- **`exists: false`** — the file has not been written yet (a never-visited site has no access log). Not an error, and not the same as a failed read.
- **`truncated: true`** — there is more log than you are being shown, either because it continues above the window or because more lines matched than `lines` asked for.
- **`search_window_capped: true`** — only meaningful with `grep`. A search only ever covers the last **5,000** lines, so this says the match may exist earlier in the file and was not looked at. **Surface it.** Without it, an empty result reads as *"this is not in your log"* when the truthful answer is *"I only looked at the end of it"*.

There is **no cursor and no `?after=`**. This reference described cursor-based tailing that was never implemented — poll the endpoint and diff client-side if you need a live tail.

---

## Application — Workers (Queue / Background Processes)

### GET `/applications/{application}/workers`
**Permission:** `app_worker` (view)

Workers are systemd units. The old supervisor-style field names (`numprocs`, `autostart`, `autorestart`, `startsecs`, `stopwaitsecs`, `status`) **do not exist** — the real shape is:

```json
{"workers": [{
  "id": 1,
  "application_id": 1,
  "name": "queue",
  "command": "php8.4 artisan queue:work --sleep=3 --tries=3 --max-time=3600",
  "kind": "queue", "kind_title": "Queue worker",
  "directory": null,
  "processes": 4,
  "stop_wait_seconds": 10,
  "auto_restart": true,
  "restart_on_deploy": true,
  "enabled": true,
  "running": 3,
  "state": "degraded", "state_title": "Degraded",
  "log_identifier": "sv-worker-1",
  "created_at": "28-07-2026 11:00:00", "created_at_human": "3 days ago"
}], "presets": [
  {"key": "queue", "kind": "queue", "title": "Queue worker", "description": "…", "command": "php8.4 artisan queue:work --sleep=3 --tries=3 --max-time=3600"},
  {"key": "horizon", "kind": "horizon", "title": "Horizon", "description": "…", "command": "php8.4 artisan horizon"},
  {"key": "custom", "kind": "custom", "title": "Custom command", "description": "…", "command": ""}
], "checks": [
  {"code": "cache_driver_array", "severity": "warning", "title": "…", "detail": "…"}
]}
```

**`running` and `state` are read from systemd on every request and never stored.** `state` is `running` (all processes up), `degraded` (some up), or `stopped` (none) — "3 of 4 running" is a real condition that a single green dot would hide, so show `running` against `processes`.

`enabled: false` means the unit is kept but not started — disabling is not deleting.

`kind` is `queue`, `horizon` or `custom`, and it decides **how** a restart happens: `queue:restart` for a queue worker, `horizon:terminate` for Horizon, a direct unit restart otherwise. It is not cosmetic. A queue worker and Horizon on the same application both consume the same queue and would run every job twice — neither tool can detect the other, so the request layer rejects the combination.

`restart_on_deploy` makes the worker pick up new code after a deploy; without it a deploy leaves the site on new code and the queue on old code, with nothing anywhere connecting the two.

`log_identifier` is the journal identifier, so the logs screen can be linked to without the frontend assembling a unit name.

`presets` are starting points for the detected framework — key/kind/command, already localised. Craft returns `queue` + `custom`; Statamic returns `queue` + `horizon` + `custom`; Node applications without a recognised queue framework (including n8n and Node-RED) return only `custom`. Detection follows the application's actual project root rather than assuming its served directory contains the CLI, so Craft's `craft`, Statamic's `please`/`artisan`, and brownfield Laravel layouts with `artisan` beside `public/` are found correctly. When `directory` is null, the worker uses that same detected project root as its working and writable directory.

`checks` is a list (empty for non-Laravel sites), each entry a `code` + `severity` + localised `title`/`detail`; `cache_driver_array` is the one worth surfacing loudly, because on the `array` cache driver `queue:restart` silently does nothing.

---

### POST `/applications/{application}/workers`
**Permission:** `app_worker` (manage) | **Throttle:** 20/min

Add a worker.

**Request:**
```json
{
  "name": "queue",
  "command": "php8.4 artisan queue:work --sleep=3 --tries=3",
  "kind": "queue",
  "directory": null,
  "processes": 2,
  "stop_wait_seconds": 10,
  "auto_restart": true,
  "restart_on_deploy": true,
  "enabled": true
}
```

`name` and `command` are required; everything else is optional. `name` is unique per application. `command` rejects shell metacharacters (`; | & \` $ < > ( )` and newlines) — it is executed directly, not through a shell. `directory` is relative to the application root, defaults to it, and may not contain `..`. `processes` is at least 1, `stop_wait_seconds` 1–600.

**Response `201`:** `{"worker": {...}}`

---

### PUT `/applications/{application}/workers/{worker}`
**Permission:** `app_worker` (manage) | **Throttle:** 20/min

Update worker settings. Changes write a new systemd unit and restart the worker.

**Request:** `{"processes": 4, "enabled": false}` — same fields as create, all optional.

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

**Response `200`:** `{"worker": {"id": 1, "running": 4, "state": "running", "state_title": "Running", …}}` — the full worker object, with `running`/`state` re-read from systemd after the action.

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

**Not available on every site type.** Cloning copies the served files; a
database-backed application needs a recipe on top of that (dump, load, rewrite
the stored URL), and only WordPress has one. So `app_clone` is absent from the
feature list of **Akaunting, CraftCMS, Joomla, Mautic, Moodle, Nextcloud,
NodeBB, PrestaShop** — and from phpMyAdmin, which holds no content of its own.
For those types this endpoint returns **`404`**, and `app_clone` is omitted
from `GET /permissions?application_id=…`, so the sidebar item does not render.
Types that need no database (static, plain PHP, git, Statamic, n8n, Node-RED,
Uptime Kuma) clone generically and are unaffected.

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

**Driven from applications, not from backup targets** — a list built from targets could only ever return the sites that are already protected, and the question this screen exists to answer is which ones are not.

Paged. `?search=` matches application name and domain; **`?filter[protected]=0`** is the one this screen exists for — the sites with no backup configured at all; `?sort=name|domain|created_at`, default `name` ascending; `?per_page=10|20|30|50|100`, default 10.

**`meta.total` / `meta.protected` / `meta.unprotected` count every application on the server**, not the current page and not the current filter — the header reads "N of M sites protected", and that sentence must not change when you turn the page. **`meta.matched` is the separate number**: how many rows the current search and filter found. Two different questions, two fields.

```json
{"backup_targets": [
  {
    "application_id": 1, "application_name": "shop", "application_domain": "shop.example.com",
    "backup_target": {"id": 1, "type": "full", "frequency": "daily", "…": "…"},
    "last_backup": {"id": 15, "status": "verified", "created_at": "28-07-2026 02:00:00", "…": "…"}
  },
  {
    "application_id": 2, "application_name": "blog", "application_domain": "blog.example.com",
    "backup_target": null,
    "last_backup": null
  }
], "meta": {"total": 7, "protected": 4, "unprotected": 3}}
```

There is no `configured` flag — `backup_target === null` is the unprotected state. `backup_target` and `last_backup` are the full objects documented below.

**`last_backup` is the newest run however it ended**, not the last successful one. A failed run replaces a good one here; read its `status` before showing a reassuring date.

---

### GET `/applications/{application}/backup-target`
**Permission:** `app_backup` (view)

Backup settings for one application.

```json
{"backup_target": {
  "id": 1,
  "application_id": 1,
  "storage_destination_id": 1,
  "storage_destination_name": "S3 Backup",
  "type": "full", "type_title": "Files and database",
  "retention_count": 7,
  "frequency": "daily", "frequency_title": "Daily",
  "schedule_time": "02:00",
  "enabled": true,
  "file_excludes": ["storage/framework/cache", "node_modules"],
  "database_excludes": ["sessions", "cache"],
  "last_run_at": "28-07-2026 02:00:00", "last_run_at_human": "3 days ago",
  "next_run_at": "29-07-2026 02:00:00", "next_run_at_human": "in 20 hours",
  "is_due": false,
  "created_at": "25-07-2026 14:30:00",
  "updated_at": "28-07-2026 02:00:05"
}}
```

There is no `schedule` or `retention` field — they are **`frequency`** and **`retention_count`**. There is no nested `storage_destination` object either: only `storage_destination_id` plus a flat `storage_destination_name` (and that name is present only when the endpoint loads the relation — it does here and on save).

`frequency` is `manual` · `daily` · `weekly` · `monthly`; `schedule_time` is `HH:MM` in the server's timezone. `type` is `filesystem` · `database` · `full`.

**Read `is_due`, not `next_run_at`, to decide whether a run is imminent.** A brand-new target is due immediately — it will run on the next scheduler tick — while `next_run_at` names the next *scheduled* slot, which can be tomorrow. The two disagreeing is correct, not a bug.

`backup_target: null` — not configured.

---

### PUT `/applications/{application}/backup-target`
**Permission:** `app_backup` (manage)

Configure or update backup settings.

**Request:**
```json
{
  "storage_destination_id": 1,
  "type": "full",
  "frequency": "daily",
  "schedule_time": "02:00",
  "retention_count": 7,
  "enabled": true,
  "file_excludes": ["node_modules"],
  "database_excludes": ["sessions"]
}
```

`storage_destination_id`, `type`, `frequency`, `retention_count` and `enabled` are all **required** — this is a full save, not a patch. `retention_count` is 1–365. `schedule_time` must be `H:i`. The two exclude arrays are optional, max 100 entries each.

**Lowering `retention_count` deletes backups immediately**, archives included, down to the new number. It used to take effect only at the end of the next run, so the setting saved and nothing happened. Raising it deletes nothing, so saving this screen can never remove more than the user just asked to keep. Verified backups only, and never a safety copy.

**Response `200`:** `{"backup_target": {...}}`

---

### DELETE `/applications/{application}/backup-target`
**Permission:** `backup` (manage) | **Throttle:** 12/min

Stop backing this application up.

**Refuses while backups exist**, naming how many, unless the caller confirms they go too:

```json
{"delete_backups": true}
```

Without it: `422` — *"This application still has 7 backup(s). Confirm that they should be deleted too, or delete them first."* The schedule is cheap to retype; the archives are somebody's only copy, so they are never removed as a side effect.

With it, every backup is deleted through the same path as the single delete below — archive first, then the row — so an archive that cannot be removed stops the whole operation rather than stranding the rest.

`422` while a backup is running. **Response `204`.**

Note the permission: `backup` (manage), not `app_backup`. Setting a schedule is a per-site decision; this can take every archive with it.

---

### POST `/applications/{application}/backups`
**Permission:** `app_backup` (manage) | **Throttle:** 6/min

Run a backup immediately.

**Response `202`:** the **backup target**, not the backup — `{"backup_target": {"id": 1, "frequency": "daily", …}}`

The `Backup` row does not exist until a queue worker picks the job up, so there is no id to return here. Find the run with `GET /backups?filter[application_id]={id}` (newest first) and poll it from there. `is_due` comes back `true` immediately after dispatch and clears once `last_run_at` is recorded.

`422` if not configured or if a backup is already in progress.

---

### GET `/backups`
**Permission:** `backup` (view)

Every backup across every application — paginated, filterable.

**Query:** `?filter[application_id]=1&filter[status]=verified&filter[type]=full&filter[from]=2026-07-01&filter[to]=2026-07-31&page=1&per_page=20`

```json
{"backups": [{
  "id": 15,
  "application_id": 1,
  "application_name": "shop", "application_domain": "shop.example.com",
  "type": "full", "type_title": "Files and database",
  "is_safety": false,
  "status": "verified", "status_title": "Complete",
  "size_bytes": 52428800,
  "reason": null, "reason_title": null,
  "log_key": "a1b2c3d4-e5f6-4890-abcd-ef1234567890",
  "reference": "9f8e7d6c-5b4a-4321-9876-0fedcba98765",
  "started_at": "28-07-2026 02:00:00",
  "finished_at": "28-07-2026 02:04:00",
  "verified_at": "28-07-2026 02:04:10",
  "created_at": "28-07-2026 02:00:00", "created_at_human": "Yesterday"
}], "meta": {"current_page": 1, "per_page": 20, "total": 45, "last_page": 3, "counts": {"total": 45, "pending": 0, "running": 1, "verifying": 0, "completed": 40, "failed": 4}}}
```

**`status` is `pending` · `running` · `verifying` · `verified` · `failed`.** There is no `completed` — a finished, checked backup is `verified`, and `filter[status]=completed` is rejected as an invalid enum value. (The `meta.counts` key *is* spelled `completed`, and counts the `verified` rows. That inconsistency is in the response; it is not a typo here.)

`verified_at` is when the archive was checked after upload, which is the only timestamp that means the backup is actually restorable. `finished_at` only means the process stopped.

The application is flattened as `application_name` / `application_domain` — there is no nested `application` object, and **no `size_human`**: format `size_bytes` yourself.

Note the label: `status: "verified"` renders as **"Complete"**, not "Verified" — `status_title` is written for the person reading the screen. Render it; do not build your own map from the raw value.

`reason` is a classified failure code with `reason_title` its localised sentence; both null unless `status` is `failed`. `log_key` and `reference` are both **UUIDs assigned when the run starts** — never null, on any status. `log_key` addresses this run's log through the logs endpoints; `reference` is the id to quote to support.

`is_safety: true` marks the automatic pre-restore snapshot rather than a backup anyone scheduled — worth distinguishing in the list so it does not read as a stray extra run.

---

### GET `/backups/{backup}`
**Permission:** `backup` (view)

Poll one backup.

Same object as the list above — `{"backup": {…}}`. Poll until `status` is `verified` or `failed`.

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

### POST `/backups/{backup}/clear`
**Permission:** `app_backup` (manage) | **Throttle:** 6/min

Close out a backup stuck in flight after its worker died — a row left at `running` or `pending` that nothing is working on any more.

**This matters because a stranded row blocks the site.** Every path that starts a backup refuses while one is in flight, so until the row is closed that application cannot be backed up at all.

**Not a cancel.** A run that could still be executing is refused with `422`, and the message says how long until it clears itself. There is no way from the panel to stop a job already running on a worker, and marking it failed would leave the row saying one thing while the process kept going — and free the guard for a second backup to start alongside the first, both writing to the same archive key.

**Response `200`:** `{"backup": {"id": 9, "status": "failed", "reason": "abandoned", …}}`

`422` if the backup is not in flight, or if it is too recent to be certain it is dead.

**You usually will not need this.** Stranded runs are closed out automatically the next time anything starts a backup for that target, and by the scheduler tick. This endpoint exists so nobody has to wait for that.

**Two `reason` values relate to this:**

| value | meaning |
|---|---|
| `crashed` | the worker died and the panel noticed — OOM, a restart mid-backup |
| `abandoned` | the run never reported back at all and was closed out later |

---

### DELETE `/backups/{backup}`
**Permission:** `backup` (manage) | **Throttle:** 12/min

Delete one backup — **the archive on the storage destination as well as the panel's record of it.**

The order is archive first, then the row, and it matters: deleting the row first would leave an object in the bucket that nothing in the panel knows about — unfindable, undeletable, and billed every month until somebody goes looking.

- **`422` while the backup is still running.** The uploader is writing to the very key this would delete.
- **`422` if the archive cannot be removed**, and the record is kept. This is deliberately stricter than automatic retention, which logs and moves on: somebody pressed a button and is owed a straight answer about whether it happened.
- **`204` when the archive was already gone.** A bucket emptied by hand must not leave a row that can never be removed.
- A safety backup (`is_safety: true`) *can* be deleted — it is the user's data and this is explicit — but the activity entry records that it was one. Automatic retention still never touches them.

**Response `204`.**

Same permission tier as restore and download rather than the per-site `app_backup`: whoever can configure a schedule is not automatically trusted to destroy what it produced.

---

## Backup — Restores

### GET `/restores`
**Permission:** `backup` (view)

Restore history — what was restored, when, and by whom. Paginated.

**Query:** `?filter[application_id]=1&filter[status]=succeeded&page=1`

```json
{"restores": [{
  "id": 3, "backup_id": 14, "application_id": 1,
  "application_name": "shop", "application_domain": "shop.example.com",
  "type": "full", "type_title": "Files and database",
  "status": "succeeded", "status_title": "Restored",
  "current_step": null, "current_step_title": null,
  "step_number": null, "total_steps": 7,
  "reason": null, "reason_title": null,
  "safety_backup_id": 16,
  "rollback_path": "/home/siteowner/.rollback-3",
  "reference": "3c2b1a09-8f7e-4d6c-5b4a-392817465fed",
  "started_at": "28-07-2026 10:00:00", "started_at_human": "3 days ago",
  "finished_at": "28-07-2026 10:05:00", "finished_at_human": "3 days ago"
}], "meta": {"current_page": 1, "per_page": 20, "total": 3, "last_page": 1}}
```

The application is flattened as `application_name` / `application_domain` — no nested object.

`safety_backup_id` — the pre-restore snapshot created automatically. `rollback_path` — the previous site directory still on disk.

`current_step` is the machine key, `current_step_title` its localised sentence, and `step_number` / `total_steps` turn it into a progress bar. `step_number` is null when nothing has started yet; `total_steps` is always populated, so a bar can be rendered before the first step reports.

The seven keys, in order: `download_artifact` · `verify_download` · `safety_backup` · `extract_archive` · `restore_database` · `swap_files` · `restart_process`. Branch on those, never on `current_step_title` — the title is translated and changes with the viewer's locale.

`status` is `pending` · `running` · `succeeded` · `failed` (labels: Queued · Restoring · Restored · Restore failed). `reference` is a UUID assigned at creation — never null.

---

### GET `/restores/{restore}`
**Permission:** `backup` (view) | **Throttle:** 120/min

Poll a running restore.

```json
{"restore": {
  "id": 3, "status": "running", "status_title": "Restoring",
  "reason": null, "reason_title": null,
  "current_step": "swap_files", "current_step_title": "Putting the files in place",
  "step_number": 6, "total_steps": 7,
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

`confirm` is **required** and must match the application's domain exactly (trimmed, case-sensitive). Everything else in this feature guards against the system going wrong; this guards against the person going wrong, which is the more common failure — so it is typed, not clicked.

`type` is **optional** and is one of **`filesystem` · `database` · `full`** — the same vocabulary as everywhere else in this feature. There is no `files`; sending it is a `422`. Omit it and the restore uses whatever the backup itself holds, which is the right default.

**Five separate `422`s, all worth distinguishing in the UI:**

| Condition | Error key on |
|---|---|
| Backup is not `status: "verified"` — we cannot prove it arrived intact | `backup` |
| The backup's application no longer exists | `backup` |
| A restore for this application is already pending or running | `backup` |
| `confirm` does not match the domain | `confirm` |
| Asked for more than the archive holds (`full` from a database-only backup) | `type` |

That last one is a subset check, not a formality: restoring `full` from a database-only backup would swap an empty directory over a working site.

**Response `202`:** the `RestoreResource` — `{"restore": {"id": 3, "status": "pending", "type": "full", "total_steps": 7, …}}`. There is no `confirm` field in the response; it is an input only.

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
  "install_status": null, "install_reason": null, "install_message": null,
  "install_progress": null
}, {
  "engine": "mariadb", "driver": "mysql", "running": true, "version": "10.11.4",
  "installed": true, "installable": true,
  "install_status": null, "install_reason": null, "install_message": null,
  "install_progress": null
}, {
  "engine": "mongodb", "driver": "mongodb", "running": false, "version": null,
  "installed": false, "installable": true,
  "install_status": null, "install_reason": null, "install_message": null,
  "install_progress": null
}]}
```

`install_status` is only ever `installing | failed | null` — never `installed`. A finished install removes its row.

While an install is queued, running, or failed, `install_progress` carries the detailed lifecycle:

```json
{
  "status": "installing",
  "started_at": "27-08-2026 04:00:00",
  "started_at_human": "a few seconds ago",
  "reason": null,
  "message": null,
  "reference": null,
  "current_step": "starting_service",
  "current_step_title": "Starting the database service",
  "output": "Setting up mariadb-server ...",
  "retryable": false
}
```

`current_step` is one of `queued`, `checking_conflicts`, `preparing_repository`, `updating_package_index`, `preparing`, `downloading`, `unpacking`, `configuring`, `starting_service`, `verifying_connection`, or `creating_panel_account`. MongoDB uses the repository steps; MySQL and MariaDB use the conflict check. Package phases are parsed from APT's real output rather than advanced on a timer. `output` is an 8 KB tail of APT output and contains no command arguments or credentials. `current_step_title` and failure `message` are localized for the viewer. On failure, the last real step remains in place and `retryable` becomes `true`.

The database component returned by `GET /setup` exposes the same object as `progress`. Once installation succeeds, the transient row is deleted, both progress objects become `null`, and `installed`/`running` are derived from the server itself.

---

### POST `/databases/engines/{engine}`
**Permission:** `database` (manage)

Install a database engine. All three are installable now — MongoDB was the last one that was not, because it is not in Ubuntu's archive and needed its own apt repository.

Do not hardcode which engines are installable: read `installable` from `GET /databases/engines`. It is driven by config, so an engine can be *operable* (the panel manages databases on one that already exists) before it is *installable*, and a `false` there means the button must not be offered.

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

Paged. `?search=` matches the database name; `?filter[engine]=mariadb` (validated against the configured engines — an unknown one is a **422**, not an empty list); `?sort=created_at|name|engine|users_count`, default `-created_at`; `?per_page=10|20|30|50|100`, default 10. Responds `meta{current_page, per_page, total, last_page}`.

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
{"name": "shop_db", "engine": "mariadb", "charset": "utf8mb4", "collation": "utf8mb4_unicode_ci", "application_id": 1,
 "create_user": {"username": "shop_user", "password": "…", "connection_preference": "localhost", "host": null}}
```

**Response `201`:** `{"database": {…}}`

`create_user` is **optional** — send it to create the database and its first user in one call instead of a second round trip. Omit it entirely for a bare database; if present, `create_user.username` is required.

Field limits differ and the difference is not arbitrary: `name` allows up to **63** characters, `create_user.username` only **32**, which is MySQL 8's hard limit on a user name. Both are `[A-Za-z0-9_]` only — identifiers cannot be parameterised in DDL, so the regex *is* the injection guard, and anything outside it is rejected rather than escaped. `password` may be omitted and one is generated.

`connection_preference` is `localhost` | `remote` | `anywhere`. **`host` is required when it is `remote`** and must be an IPv4 address or CIDR.

`collation` must belong to the chosen `charset` — a valid-but-mismatched pair fails validation on `collation`, it is not silently corrected. Reserved system schema names are refused.

---

### GET `/databases/{database}`
**Permission:** `database` (view)

Size is re-measured on this single-record view (exact figure worth one query).

```json
{"database": {"id": 1, "name": "shop_db", …, "users": [{…full user object…}]}}
```

**The list and the single record carry different keys, and neither carries both.** `GET /databases` returns `users_count` and no `users`; this endpoint returns `users` and no `users_count` — each is omitted, not null, when the query did not ask for it. Do not write `db.users?.length ?? db.users_count`; branch on which endpoint you called.

Each entry in `users` is the **full** `DatabaseUserResource` documented under `GET /databases/{database}/users` — password and connection string included, not the `{id, username}` stub. `POST /databases/adopt` and the update endpoints return neither key.

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


**Database users are not adopted here** — `POST /server/sync` finds them once the database is tracked. An adopted user has **no password**: the engine stores a hash and a hash is not a password. Such a user comes back with `password_known: false` and `connection_string: null` rather than a string that looks right and fails at connect time. Setting a new password is a deliberate act, since it breaks every application still using the old one.


## Server Sync

Reads a migrated server into the panel. `preview` (the default) changes nothing; `apply` writes the rows. Nine resource types, dependency-ordered: `system_user` → `ssh_key` → `application` → `php_settings` → `worker` → `database_user` → `certificate` → `cronjob` → `firewall_rule`.

`firewall_rule` is the one that has to be asked for: it is skipped unless `include_firewall: true`, because adopting a rule set is the one step here that can lock you out of the box.

### POST `/server/sync`
**Permission:** `sync` (manage) | **Throttle:** 10/min

`{"mode": "preview|apply", "only": [], "include_firewall": false, "include_ignored": false}` → **202** `{"sync": {"id": 1, "status": "pending"}}`

An omitted `mode` is **preview**. Refused with `422` while another run is live.

### GET `/server/sync/{run}?since=<item id>`
**Permission:** `sync` (view)

The run plus items **after the cursor**, so a screen can poll ~1s and append — this is the line-by-line feed.

```json
{"sync": {
  "id": 1,
  "mode": "preview",
  "status": "running",
  "finished": false,
  "options": {"only": [], "include_firewall": false, "include_ignored": false},
  "totals": {"found": 12, "adopted": 0, "skipped": 3, "failed": 0},
  "started_at": "12-08-2026 09:00:00",
  "finished_at": null,
  "items": [{
    "id": 87,
    "resource_type": "application",
    "resource_key": "shop.example.com",
    "action": "found",
    "confidence": "high",
    "evidence": "wp-config.php",
    "reason": null,
    "model_id": null
  }]
}}
```

Poll on **`finished`** rather than comparing `status` against a list of terminal values.

`totals` is the running tally per action, so the header does not have to be reduced from a paged item list — and stays correct when the caller is only fetching items after a cursor.

`model_id` is the id of the row that was created, and is **null on a preview run and on any item that was not adopted**. It is what turns a finished line into a link to the thing it created.

`confidence` and `evidence` exist because a site's type is *inferred* (`wp-config.php`, `artisan`, `package.json`). A site mislabelled WordPress will later have `wp` commands run at it, so show the evidence rather than presenting the guess as fact.

`items` is absent unless the endpoint loads it.

### GET `/server/sync/latest` · GET/POST `/server/sync/ignores` · DELETE `/server/sync/ignores/{ignore}`
**Permission:** `sync` (view / manage)

Dismissed items stop appearing in later runs entirely. `POST {"resource_type", "resource_key", "note"}`; re-posting the same pair is the same decision, not an error. Pass `include_ignored: true` on a run to see them again.

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
**Permission:** `database` (manage) | **Throttle:** 6/min

Dump a database to a file. Queued.

`manage`, not the read tier: this copies an entire database off the server, which is more revealing than `optimize` or `repair` — and those have always needed `manage`.

**Response `202`:** `{"export": {"id": 1, "status": "queued", "file": null}}`

Poll `GET /databases/exports`.

**One export per database at a time.** A second request while one is queued or running is a **`422`** naming that, rather than a second `mysqldump` writing another full copy to the same disk. A run whose worker was killed is closed out automatically before the check, so a stranded row cannot block a database permanently — it gets `status: failed`, `reason: worker`.

---

### GET `/databases/exports`
**Permission:** `database` (view)

All exports, newest first. Includes in-flight rows.

```json
{"exports": [{
  "id": 1, "database_id": 1, "database": "shop_db", "engine": "mariadb",
  "status": "completed", "file": "shop_db-2026-07-28-100000.sql.gz",
  "size_bytes": 2097152, "size_human": "2 MB",
  "reason": null, "message": null, "reference": null,
  "available": true,
  "download_url": "https://panel.example.com/api/databases/exports/shop_db-2026-07-28-100000.sql.gz",
  "requested_by": {"id": 1, "username": "admin"},
  "created_at": "28-07-2026 10:00:00", "created_at_human": "Yesterday",
  "finished_at": "28-07-2026 10:00:12", "finished_at_human": "Yesterday"
}]}
```

The database name is `database`, not `database_name`.

**`available` is not the same as `status: "completed"`** — it also checks the file is still on disk. A retention sweep or a manual delete leaves a completed row whose file is gone; render the download button off `available`, and `download_url` is null in exactly that case.

`reason` is a classified failure code and `message` its localised sentence; `reference` is the id to quote to support. `requested_by` is absent unless the endpoint loads the user, and null when the export was made by the system rather than a person.

---

### GET `/databases/exports/{file}`
**Permission:** `database` (manage)

Stream a previously-created export for download. Filename is strictly validated (alphanumeric + `.` `-` `_` only).

`manage`, matching the backup download and for the same reason: this hands over an entire database in one request. **Listing** the exports stays on the read tier — knowing a dump exists is not the same as being handed it.

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
  "password": "s3cr3t…",
  "password_known": true,
  "connection_preference": "localhost",
  "host": "localhost",
  "connection_string": "mariadb://shopuser:s3cr3t%E2%80%A6@127.0.0.1:3306/shop_db",
  "created_at": "25-07-2026 14:30:00", "created_at_human": "2 weeks ago"
}]}
```

**`connection_string` is a full URI with the password in it**, not a `user@host` label — it exists to be copied into an application's config. It is null when the password is unknown or the `database` relation is not loaded. `localhost` and `%` are rendered as `127.0.0.1` so the string works when pasted.

**`password_known: false` is the adopted-database case.** A user imported from an existing server has no password the panel ever saw, so `password` is null and no connection string can be built — offer "set a new password" rather than showing an empty field that looks like a bug.

The password is returned in full, deliberately: the user has to paste it into their application and will come back for it. Same reasoning as the system-user password.

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

The frontend receives a `redirect_url` and should immediately redirect the browser to it. The URL contains a one-time token (TTL 60 s) that the `sso.php` script on the phpMyAdmin site consumes — it deletes the token before using it, so the link works exactly once.

**Never assume the scheme.** `redirect_url` is `http://` until the phpMyAdmin site has a servable certificate and `https://` afterwards; redirect to the URL as given. A site with no certificate has no TLS listener at all, so an assumed `https://` is a connection refused.

**Query (optional):** `?database_user_id=1` — log in as a specific database user. Without this the first available user is used.

**Response `200`:**
```json
{"redirect_url": "http://pma.example.com/sso.php?token=***"}
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

**Response `422`** — the phpMyAdmin site shares the server-wide PHP pool. The sign-in link is delivered through a file only that site's own account may read, which is only true when the site has its own PHP-FPM pool. On OpenLiteSpeed, where no per-site pool exists, this endpoint is never available and the user should open phpMyAdmin and sign in with the database credentials:
```json
{"message": "This phpMyAdmin site shares the server-wide PHP pool, so a sign-in link would be readable by every other site. …"}
```

Signing in this way writes a `database.phpmyadmin_signed_in` row to the activity log, naming the panel user, the database and the database account used.

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

Paged. `?search=` matches the username; `?sort=created_at|username`, default `-created_at`; `?per_page=10|20|30|50|100`, default 10. Responds `meta{current_page, per_page, total, last_page}`.

```json
{"system_users": [{
  "id": 1, "username": "siteowner",
  "home_path": "/home/siteowner",
  "shell": "/bin/bash",
  "shell_title": "Full shell access (bash)",
  "shell_allows_login": true,
  "sudo": false, "ssh_access": false,
  "password": null,
  "created_at": "23-07-2026 10:00:00", "created_at_human": "3 weeks ago"
}]}
```

---

### POST `/system-users`
**Permission:** `system_user` (manage)

**Request:** `{"username": "newuser", "password": "…", "shell": "/bin/bash", "sudo": false, "ssh_access": false, "public_key": null}`

Everything but `username` is optional. `shell` defaults to `/bin/bash`; `sudo` and `ssh_access` default to `false`.

**Response `201`:** `{"system_user": {...}}`

**`422` on `ssh_access`** when it is `true` alongside a shell with `allows_login: false`.

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

**Request:** `{"sudo": true}`

**Response `200`:** `{"system_user": {...}}` (the full resource)

---

### GET `/system-users/shells`
**Permission:** `system_user` (view)

The shells that may be assigned, in words. Build the picker from this — do not hardcode the paths or invent labels for them.

```json
{"shells": [
  {"value": "/bin/bash",        "title": "Full shell access (bash)", "description": "The standard Linux shell. The user can log in over SSH and run commands.", "allows_login": true},
  {"value": "/bin/sh",          "title": "Basic shell (sh)",         "description": "…", "allows_login": true},
  {"value": "/usr/bin/zsh",     "title": "Full shell access (zsh)",  "description": "…", "allows_login": true},
  {"value": "/usr/sbin/nologin","title": "No login",                 "description": "The user owns its files and runs the site, but cannot log in…", "allows_login": false},
  {"value": "/bin/false",       "title": "No login (legacy)",        "description": "…", "allows_login": false}
]}
```

`title` and `description` are localised. Anything outside this list is rejected with `422`.

---

### PUT `/system-users/{systemUser}/shell`
**Permission:** `system_user` (manage)

**Request:** `{"shell": "/usr/sbin/nologin"}`

**Response `200`:** `{"system_user": {...}}` (the full resource)

**`422` on `shell`** when the user currently has `ssh_access: true` and the chosen shell has `allows_login: false` — sshd would authenticate and the session would close immediately. Turn SSH access off first, or pick a login shell.

---

### PUT `/system-users/{systemUser}/ssh`
**Permission:** `system_user` (manage)

Enable/disable SSH login for this system user.

**`422` on `ssh_access`** when enabling it for a user whose shell has `allows_login: false` — the same contradiction from the other side.

> **Known limitation.** This records intent. Enforcement needs `AllowGroups ssh-users` in `sshd_config`, which the panel does not write yet, so a user with a password and a login shell can still connect while this reads `false`. Do not present it as a hard security control until that lands.

**Request:** `{"ssh_access": false}`

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
  {"key": "laravel", "label": "Laravel Scheduler", "command": "php {path}/artisan schedule:run", "expression": "* * * * *"},
  {"key": "wordpress", "label": "WP Cron", "command": "php {path}/wp-cron.php", "expression": "*/5 * * * *"},
  {"key": "craftcms", "label": "Craft CMS queue", "command": "php {path}/craft queue/run", "expression": "* * * * *"},
  {"key": "custom", "label": "Custom", "command": null, "expression": null}
], "placeholder": "{path}"}
```

Each preset carries a **`command` and a separate `expression`** — the schedule is not embedded in the command string. `custom` has both `null`; it is the "let me write my own" entry, not a preset with a missing command. `label` is localized, `key` is not — render the label, key off the key.

**Substitute `{path}` with the application's `path` field** (see `GET /applications/{id}`), *not* `document_root`. They are the same directory for most site types and different for Craft and Statamic, where the binary these presets invoke sits above the served directory. A cron job pointed at a file that is not there does not error — it runs, finds nothing, and reports success.

---

### GET `/cronjobs`
**Permission:** `cronjob` (view)

Paginated, filterable. Filters: `filter[system_user_id]`, `filter[application_id]`, `filter[username]`, `filter[active]`.

```json
{"cronjobs": [{
  "id": 1,
  "name": "Laravel scheduler",
  "slug": "laravel-scheduler",
  "username": "siteowner",
  "system_user": {"id": 1, "username": "siteowner"},
  "application_id": null,
  "command": "cd /home/siteowner/shop.example.com && php artisan schedule:run",
  "expression": "*/5 * * * *",
  "active": true,
  "timezone": "Europe/Berlin",
  "log_key": "cron-laravel-scheduler",
  "next_run_at": "29-07-2026 10:00:00", "next_run_at_human": "in 4 minutes",
  "created_at": "25-07-2026 14:30:00", "created_at_human": "2 weeks ago"
}], "meta": {"current_page": 1, "per_page": 10, "total": 3, "last_page": 1}}
```

The account is `username` (a plain string, always present), not `user`. `system_user` is the linked record and is **absent unless the endpoint loads it**, and null for a job whose account is not a panel-managed system user — a job adopted from an existing crontab is the normal case.

There is **no `human` field** (render the expression client-side or use the schedule presets endpoint) and **no `last_run_at`** — cron does not record one. `next_run_at` is computed from the expression against `timezone`, which is the server's timezone and is returned on every row so the frontend never has to assume UTC.

`slug` is the identity on disk (`/etc/cron.d/<slug>`); `log_key` addresses this job's captured output through the logs endpoints.

---

### POST `/cronjobs`
**Permission:** `cronjob` (manage)

**Request:**
```json
{"name": "Laravel scheduler", "system_user_id": 1, "username": null,
 "command": "cd /home/siteowner/shop.example.com && php artisan schedule:run",
 "expression": "*/5 * * * *", "active": true}
```

**`name` is required and unique** — it is the job's identity on disk (slugified into `/etc/cron.d/<slug>`), so a duplicate is a `422` on `name`, not a second file. Reserved cron filenames are refused.

**The account is one of two fields, not one:** send `system_user_id` to target a panel-managed System User, or `username` for a raw OS account that the panel does not manage (the normal case on a migrated server). `username` is `required_without:system_user_id`, so a request carrying neither is a `422` — there is no `user_id` field.

`command` must be a single line, max 1000 chars, and may not still contain the `{path}` placeholder from a command preset — an unresolved placeholder is rejected rather than written to cron as literal text. `expression` is validated as a real cron expression.

**`application_id` scopes the job to a site.** Optional; null (or omitted) is a server-level job. Send it when the job is created from a site's own Cronjobs screen — `filter[application_id]` on the list endpoint then returns it, which it could not before, because nothing was able to set the column.

**Response `201`:** `{"cronjob": {...}}`

**Response `500` — the job could not be written to the server.** Writing one touches seven privileged steps, and **`code` names the one that failed** so the message can be acted on rather than just reported:

| `code` | what failed |
|---|---|
| `cronjob_log_dir` | creating the shared cron log directory |
| `cronjob_log_touch` | creating this job's log file |
| `cronjob_log_chown` | handing the log to the account the job runs as |
| `cronjob_log_chmod` | setting the log's permissions |
| `cronjob_rotation` | installing the logrotate policy — **the job is refused rather than left to grow its output without limit** |
| `cronjob_write` | the `/etc/cron.d` file itself |
| `cronjob_chmod` | the cron file's mode — cron ignores a file it does not trust, so the job would never run |

```json
{"message": "The cron file could not be written. Check there is free disk space.",
 "code": "cronjob_write", "reference": "…"}
```

**No half-made job is left behind** — the row is deleted if the write fails, because a schedule the panel lists and the server does not have is worse than none. The attempt is recorded in the activity log as `cronjob.create_failed` with the failing step, so a failure that vanished from the UI is still traceable afterwards.

`503` with `code: server_busy` instead means a lock was held and the write never started — that one is worth retrying.

**Update and delete** report the same way: `cronjob_remove` (the file could not be deleted, so the job is still scheduled), `cronjob_remove_stale` (the old file after a rename), `cronjob_detach_source` (the file an adopted job was imported from). In every one of those the panel restores what it changed, so a failed edit never leaves two schedules running.

---

### GET `/cronjobs/{cronjob}`
**Permission:** `cronjob` (view)

Same shape as one row of the list endpoint — there is no `user_id` on it.

```json
{"cronjob": {"id": 1, "name": "Laravel scheduler", "slug": "laravel-scheduler", "username": "siteowner", "system_user": {"id": 1, "username": "siteowner"}, …}}
```

---

### PUT `/cronjobs/{cronjob}`
**Permission:** `cronjob` (manage)

Every field is `sometimes` — send only what changed. Accepts `name`, `command`, `expression`, `active`, and the run-as account.

**The account can be changed here.** Send `system_user_id` or `username`, exactly as on create. An account that does not exist on the server is a **`422` on `username`** — cron accepts a file naming an unknown user and then silently fails to run it every tick, so it is refused up front. (This used to be accepted and ignored: the request answered `200` and the job kept running as its old account.)

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

**`ip` is the machine's own first local interface address** (`hostname -I`), not
necessarily its public one. On a VPS with a directly-attached public address —
Hetzner, DigitalOcean, Linode, Vultr — the two are the same, which is the usual
case. Behind NAT (most of AWS, GCP, Azure, or with a floating IP) this is the
**private** address, e.g. `10.0.0.5`.

It is therefore **not** the same value as `capabilities.server_ip` on
`GET /server/capabilities`, which is public-or-`null` and never reports a
private address. Use `server_ip` when the answer has to be reachable from the
internet (DNS, certificates, a hostname you hand to a user); use `facts.ip`
only to show the operator what is on the box's own interface. The same server
can legitimately report `facts.ip: "10.0.0.5"` and `server_ip: null`.

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

**Nothing here is ever null.** CPU percent needs two samples, and on the very first poll there is no previous one — the endpoint compares the reading against itself, so `cpu.percent` comes back as `0` (a float), not `null`. Same for `network` and `disk_io`, which are per-second rates. A first-poll `0` is therefore indistinguishable from a genuinely idle server: discard the first sample rather than rendering it, or accept one tick of understated load.

---

### GET `/server/metrics/history`
**Permission:** `dashboard` (view)

24h series for the charts (5-min cadence).

```json
{"metrics": [
  {"sampled_at": "28-07-2026 00:00:00", "cpu": 8.2, "memory": 45.1, "swap": 0, "disk": 58,
   "load_1": 0.3, "load_5": 0.42, "load_15": 0.51,
   "net_in": 5120, "net_out": 2048, "disk_read": 0, "disk_write": 0},
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

Live status of every managed systemd service. Compatibility aliases are collapsed by systemd's canonical unit ID, so one daemon produces one row. For example, when `mysql.service` is an alias of `mariadb.service`, the response contains only the canonical MariaDB catalog row; actions on a duplicate MySQL row are never offered.

```json
{"services": [{
  "key": "nginx", "label": "Nginx", "unit": "nginx",
  "state": "installed",
  "status": "active", "enabled": true, "protected": true,
  "actions": ["start", "stop", "restart", "reload"],
  "testable": true,
  "install_reason": null, "install_message": null, "retryable": false,
  "usage": {"memory_bytes": 5242880, "memory_human": "5 MB", "memory_percent": 0.06, "cpu_percent": null, "tasks": 4}
}]}
```

**Every row has the same shape, whatever kind of service it is** — a database engine, a PHP-FPM version, nginx. Two fields, two jobs:

- **`status`** — how it is doing, in systemd's three words: `active`, `inactive`, `failed`. Never null, never anything else. Render it the same way for every row.
- **`state`** — what kind of row it is: `installed | installing | install_failed`. Switch on this for behaviour.

| `state` | `status` | meaning |
|---|---|---|
| `installed` | systemd's answer | the unit exists |
| `installing` | `inactive` | being installed right now; no unit yet, so nothing is running |
| `install_failed` | `failed` | the install was attempted and failed; `install_reason` and `install_message` say why, `retryable` is `true` |

A failed install reports `status: failed` deliberately — to the person looking at the row it is broken and should read as broken, and the badge only speaks those three words. The distinction between "the service crashed" and "it never installed" lives in `state`, so nothing is lost.

A service the panel can install now appears **while it is installing and after a failed install**, instead of being absent — the row vanishing entirely reads as "the panel forgot", when the truth is "still going" or "it failed". Covers **the database engines, fail2ban, and each PHP-FPM version**. Anything with no installer (nginx, Apache, Redis, Supervisor) is still absent until its unit exists, and OpenLiteSpeed contributes no PHP rows at all because LSPHP has no per-version unit.

**Those rows are inert:** `actions` is `[]`, `usage` and `log_keys` are empty, `testable` is `false`. There is no unit to act on. Retry belongs on the setup page, which is where the install was started — `retryable` is there so the row can link to it, not so it grows its own button.

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

**Response `200`:** `{"service": {…refreshed service object…}}`

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

**The payload is flat — there is no `firewall` wrapper object.**

```json
{
  "enabled": true,
  "default_policy": {"incoming": "deny", "outgoing": "allow"},
  "rules": [{
    "id": 1,
    "port_from": 22, "port_to": null,
    "protocol": "tcp",
    "action": "allow",
    "source_ip": "203.0.113.5/32",
    "description": "Office IP",
    "origin": "user",
    "enabled": true,
    "protected": false,
    "summary": "Allow 22/tcp from 203.0.113.5/32",
    "created_at": "23-07-2026 10:00:00", "created_at_human": "3 weeks ago"
  }],
  "your_ip": "203.0.113.5",
  "ssh_port": 22,
  "listening": [{"port": 3306, "protocol": "tcp", "address": "0.0.0.0", "program": "mariadbd"}],
  "risky_ports": [{"port": 3306, "service": "MySQL", "reason": "Database server"}]
}
```

A rule is `action` (`allow` / `deny`) over a port **range** — `port_from` plus optional `port_to` — not `type` + `port`. The source is `source_ip` (IP or CIDR, null meaning anywhere), and the note is `description`, not `label`. `protocol` is `all` · `tcp` · `udp`.

`summary` is the whole rule as one localised sentence ("Allow 443/tcp from Anywhere") — use it for the row rather than reassembling the parts in the frontend.

`enabled: false` means kept but not applied; disabling is not deleting. `protected: true` marks system-seeded rules (`origin` other than `user`) that cannot be deleted — hide the delete action rather than letting it 422.

`your_ip` is the caller's own address, so "only my IP" is one click; without it people open ports to everyone rather than go and look their address up.

`ssh_port` is read here rather than from Settings on purpose: a user with firewall access but not settings access would get a 403 there and fall back to 22, and being wrong about the SSH port on this screen is how people lock themselves out.

`listening` is what is actually bound right now, from `ss`. `program` is often null — the panel's PHP process is unprivileged and cannot see another user's process name — so render the port even when the program is unknown.

`risky_ports` — ports detected from installed database engines + config, to warn before opening them.

**This endpoint can fail with `500`** (`{message, code, reference}`) when `ufw status` cannot be read. It used to answer `enabled: false` in that case, which is not "unknown" but a specific wrong answer — the screen reported an active firewall as off. Render the error rather than a disabled firewall.

---

### GET `/firewall/rules`
**Permission:** `firewall` (view)

The rules on their own, paged — use this for the rules table rather than reading `rules` out of `GET /firewall`.

Separate endpoint on purpose: `GET /firewall` also reports live UFW status and the listening ports, and building `listening[]` shells out to `ss`. Turning a page should not re-run that. `GET /firewall` still returns its full `rules` array unchanged, so nothing breaks before the frontend migrates.

`?search=` matches port, source IP and description (text match, so `80` finds both 80 and 8080); `?filter[enabled]=0|1`, `?filter[action]=allow|deny`, `?filter[origin]=user|default|db_user`; `?sort=created_at|port_from|action|protocol`, default `-created_at`; `?per_page=10|20|30|50|100`, default 10.

```json
{"rules": [{"id": 1, "port_from": 443, "…": "…"}],
 "meta": {"current_page": 1, "per_page": 10, "total": 12, "last_page": 2}}
```

---

### POST `/firewall/rules`
**Permission:** `firewall` (manage)

Add a rule.

**Request:** `{"action": "allow", "port_from": 22, "port_to": null, "protocol": "tcp", "source_ip": "203.0.113.5/32", "description": "Office IP"}`

`action`, `port_from` and `protocol` are required. `port_to` is optional and must be ≥ `port_from`. `source_ip` accepts a bare IP or CIDR; null means anywhere.

**A `/0` mask is refused** (`422` on `source_ip`): `0.0.0.0/0` reads as a rule restricted to a source and is not one. Leave `source_ip` null to mean anywhere — that is what the row then says.

**Response `201`:** `{"rule": {…}}` — note the key is **`rule`**, not `firewall_rule`.

---

### PUT `/firewall/rules/{firewallRule}`
**Permission:** `firewall` (manage)

Every field is `sometimes`. Accepts `port_from`, `port_to`, `protocol`, `action`, `source_ip`, `description`, `enabled`. There is no `address` field — the source is `source_ip`.

**Request:** `{"source_ip": "203.0.113.10/32"}`

**Response `200`:** `{"rule": {...}}` — the same key as create, **not** `firewall_rule`.

**`422` on `port_to`** when a partial edit would invert the range. Sending `port_from` alone is compared against the *stored* `port_to`, so moving a 9000–9100 rule to start at 9500 is refused rather than saved backwards.

**`422` on `rule`** when the edit would remove the last way in — see the lockout guard below.

---

### DELETE `/firewall/rules/{firewallRule}`
**Permission:** `firewall` (manage)

**Response `204`:**

**`422` on `rule`** — the lockout guard. While the firewall is enabled, a rule may not be deleted, switched off, or moved off the port if it is **the last `allow` rule covering the live SSH port**. The message names the port and says what to do (add another rule for it, or disable the firewall first). Surface it on the row, not only as a toast — it is the difference between an edit being refused and the user losing the server.

This is not the same as `protected`. `protected` marks system-seeded rules; the lockout guard asks whether a way in survives, so it also fires for a rule the user made themselves and for range rules like `20:30` that happen to cover SSH.

---

### PUT `/firewall/toggle`
**Permission:** `firewall` (manage)

Enable or disable the firewall entirely.

**Request:** `{"enabled": false}`

**Response `200`:** flat, no wrapper — `{"enabled": false, "default_policy": {"incoming": "deny", "outgoing": "allow"}}`

Enabling seeds allow rules for the web ports and for **the port SSH is actually listening on**, read from the live sshd configuration rather than from a stored default. If any of those cannot be applied, enabling is refused with a `500` rather than leaving the box behind a deny-incoming policy with no way in.

---

## Fail2ban

### GET `/fail2ban`
**Permission:** `fail2ban` (view)

```json
{"fail2ban": {
  "installed": true,
  "running": true,
  "version": "0.11.2",
  "install": null,
  "your_ip": "203.0.113.5",
  "settings": {"bantime": 3600, "findtime": 600, "maxretry": 5, "ignore_ips": ["203.0.113.5"]},
  "jails": [{
    "name": "sshd", "label": "SSH", "lockout_risk": true,
    "options": {"mode": "aggressive", "port": "{ssh_port}"},
    "enabled": true,
    "banned": ["198.51.100.9"],
    "stats": {"total_bans": 12, "current_bans": 1}
  }],
  "banned": ["198.51.100.9"],
  "bantime_presets": [{"key": "hour", "seconds": 3600, "label": "1 hour"}]
}}
```

`lockout_risk` is a **boolean**, not a level — it marks the jails that watch SSH
and can therefore lock the caller out of their own server. `your_ip` is the
caller's own address, so the UI can offer "add my IP to the ignore list" before
they enable one. See `PUT /fail2ban` for the 422 that enforces this.

`settings` and `banned` are `null`/`[]` until fail2ban is installed. A jail that
is not enabled reports `banned: []` and `stats: null` rather than zeros — not
running and nothing caught are different statements.

**`install` is the progress of an install in flight**, and `null` once fail2ban
is on disk:

```json
{"install": {
  "status": "installing",
  "reason": null, "reason_title": null, "reference": null,
  "started_at": "12-08-2026 15:20:11", "finished_at": null
}}
```

`status` is `installing` or `failed`. On failure `reason` is a stable code
(`package_not_found`, `apt_lock`, `network`, `no_space`, `worker`, `unknown`),
`reason_title` is that rendered in the caller's locale, and `reference` locates
the server-ops log entry for support.

**The screen needs three states, not one boolean.** `installed` is derived from
the package being on disk and apt is allowed ten minutes, so a UI built on
`installed` alone shows nothing at all for ten minutes and nothing when it
fails. Render *installing* (progress), *failed* (`reason_title` + retry +
`reference`), and *installed*.

---

### POST `/fail2ban/install`
**Permission:** `fail2ban` (manage)

Install fail2ban if not present.

**Response `202`:** `{"message": "Installing Fail2ban."}`

---

### PUT `/fail2ban`
**Permission:** `fail2ban` (manage)

Update ban settings and which jails are on. The thresholds are **server-wide, not per jail** — the request is flat, with `jails` as an on/off map:

**Request:**
```json
{
  "bantime": 3600, "findtime": 600, "maxretry": 5,
  "ignore_ips": ["203.0.113.5", "10.0.0.0/8"],
  "jails": {"sshd": true, "nginx-http-auth": false},
  "acknowledged": false
}
```

`bantime`, `findtime` and `maxretry` are **required**. `bantime` accepts `-1` for a permanent ban; otherwise the floor is 60 seconds, because a shorter ban expires before it inconveniences anyone and only looks like protection. The ceiling is a year. `findtime` is 30–86400. `maxretry` is 2–100 — one failure is a typo, not an attack.

`ignore_ips` is an array of IPs or CIDRs (max 100). `jails` is an object keyed by jail name with boolean values; omitted jails keep their current state. Omitting `ignore_ips` or `jails` entirely leaves them as they are.

**`acknowledged` is the lockout guard.** Enabling a jail flagged as lockout-risky (the SSH jail) while the caller's own IP is *not* in `ignore_ips` is refused with **`422`** — that request can lock the user out of their own server. The frontend should catch that 422, warn, and offer either "add my IP to the ignore list" or a re-submit with `acknowledged: true`. The guard does not fire if the jail is already active, or if the caller's IP is already ignored.

**Response `200`:** `{"fail2ban": {"settings": {...}, "jails": [...]}}`

Updates are rollback-safe: the panel validates the new `/etc/fail2ban/jail.local` with `fail2ban-client -t` before reload. If validation or reload fails, it restores the prior configuration and reloads that known-good version. The API returns the original operation failure with its support reference; the frontend should refetch `GET /fail2ban` after a failed save rather than keeping optimistic form values.

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
{"activity_log": [{"id": 1, "type": "cronjob", "action": "created", "scope": "server", "description": "Cronjob created", "user": {"id": 1, "username": "admin"}, "is_system": false, "created_at": "29-07-2026 10:00:00", "created_at_human": "3 hours ago"}], "meta": {"current_page": 1, "per_page": 20, "total": 45, "last_page": 3}}
```

**There is no `properties` field on the row.** The raw properties bag is the *input* to `description` — it is interpolated into the localised sentence server-side and never returned, so do not plan a UI that reads values out of it. Every row here has `scope: "server"` by construction.

`search` matches on `type` and `action` only — unlike the admin-wide log, it does not reach the actor's name.

---

## Logs (Server-wide)

### GET `/logs`
**Permission:** `logs` (view)

All available log sources on the server.

```json
{"logs": [{
  "key": "nginx_error", "label": "Nginx Error Log", "group": "web",
  "kind": "file", "size": 4096, "modified": "29-07-2026 11:00:00",
  "readable": true, "follow": true, "downloadable": true
}]}
```

`kind` — `file` · `privileged` · `journal`. Most sources are files the panel
reads directly. **`privileged`** is a file it cannot open as its own user and
reads through the system instead (Let's Encrypt: `/var/log/letsencrypt` is
`0700 root`). **`journal`** is the systemd journal, which is not a file at all.

Two flags follow from that, and both are worth honouring rather than inferring
from `kind`:

- **`follow`** — whether `?after=` works. Only `file` sources have byte offsets
  to come back to; for the others, poll by re-reading instead and expect
  `cursor: null`.
- **`downloadable`** — whether `/download` will work. `false` for anything that
  is not a plain file: there is no handle to stream, and piping it out through
  the system would hold a worker for the whole transfer. Calling it anyway
  answers `422`, not a broken file.

For a `journal` source `size` and `modified` are **null** — it has no single
size or last-write time, and a zero would read as an empty log last touched in
1970.

`group`: `web | database | cache | php | system | security | daemon | cronjob`. (`cache` is Redis; `cronjob` is one entry per cron job that has captured output, labelled with the job's name.)

**The list is what exists on this server, not a fixed set.** Every source is filtered by whether it is really there — a file check, a privileged file check, or asking `journalctl` for one line — so a box with no MTA has no `mail` entry, a box running Apache has no `nginx_*` entries, and a box without certbot has no `letsencrypt`. Render whatever comes back rather than expecting fixed keys. System sources currently registered: `syslog`, `auth`, `kernel`, `mail`, `journal`.

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

**`safe: false` means the category may be cleaned manually but never on a schedule.** Today that is `apt_orphans`: everything else here removes files that come back, while that one removes *packages*, on the strength of apt's auto-installed flags — which are routinely wrong on a migrated or script-built server — and `--purge` takes their configuration too. Show it in the manual list, and hide or disable it in the schedule form; `PUT /disk-cleaner/schedule` refuses it with a `422` on `categories.*`.

`paths` is display-only and may include an exclusion note rather than a pattern — `rotated_logs` lists `"excluding /var/log/mysql"`, because database binary logs live there and are removed with `PURGE BINARY LOGS`, never by deleting files.

---

### POST `/disk-cleaner/clean`
**Permission:** `disk_cleaner` (manage)

**Request:** `{"categories": ["apt_cache", "journal"]}`

**Response `200`:** `{"run_id": 12, "disk": {…refreshed…}, "cleaned": [{"key": "apt_cache", "freed": 104857600, "freed_human": "100 MB"}], "freed_total": 524288000, "freed_total_human": "500 MB"}`

⚠️ **This runs synchronously and can outlive the request.** A clean may take several minutes — `apt_orphans` alone is allowed 300 seconds — while nginx/PHP-FPM cut the request at around 60, so a large clean returns a gateway error while it is still running and finishes without the caller ever seeing the result. **Known; a queued version returning a run id to poll is planned**, and it will change this response shape. Until then, treat a timeout as "probably still running" and re-read `GET /disk-cleaner/runs` rather than telling the user it failed.

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

`categories` is what was asked for; `freed` is a per-category map of bytes actually reclaimed, so a category that ran and freed nothing is visible as `0` rather than missing. `disk_percent` is disk usage **after** the run, which is what makes the history worth keeping — it answers whether cleaning is still helping.

```json
{"runs": [{
  "id": 1, "trigger": "scheduled",
  "categories": ["apt_cache", "journal"],
  "freed": {"apt_cache": 83886080, "journal": 20971520},
  "freed_total": 104857600, "freed_total_human": "100 MB",
  "status": "success",
  "disk_percent": 58,
  "created_at": "27-07-2026 03:00:00", "created_at_human": "5 days ago"
}], "meta": {"current_page": 1, "per_page": 20, "total": 5, "last_page": 1}}
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

**Request:** `{"delay_minutes": 5}` — optional, 0–60, defaults to `0`. `0` = now.

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

**Request:** `{"security_updates_enabled": true, "auto_reboot": true, "reboot_time": "06:00", "reboot_with_users": false}`

`reboot_time` is `HH:MM` (24-hour) or the literal string `"now"`.

`reboot_with_users` decides whether an automatic reboot proceeds while someone is logged in over SSH. It is optional so an older client keeps working, and **absent means `false`** — note that this inverts the upstream unattended-upgrades default of `true`, so send it explicitly rather than relying on the default matching what the OS would do on its own.

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

**`status`** on every row: `installing | ready | removing | failed`. `ready` is never stored — detected from the filesystem.

**`failed` rows persist** until retried. `reason`: `package_not_found | apt_lock | no_space | network | worker | enable_failed | unknown`. `message` is localised. `reference` locates raw apt output.

**`installing` and `removing` rows have no other fields** — the requested filesystem state is still changing.

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
{"git_accounts": [{
  "id": 1,
  "provider": "github", "provider_title": "GitHub",
  "label": "Personal",
  "identifier": "devuser",
  "host": null,
  "workspace": null,
  "scopes": ["repo", "read:user"],
  "last_verified_at": "29-07-2026 10:00:00", "last_verified_at_human": "2 hours ago",
  "created_at": "25-07-2026 14:30:00", "created_at_human": "2 weeks ago"
}]}
```

The account name is `identifier`, not `username`. **There is no `status` or `expires_at` here** — token health is never cached on this record; ask `GET /integrations/git/accounts/status` for it, since a token can be revoked at any moment.

`host` is null for the hosted service and set for a self-hosted GitLab/Bitbucket. `workspace` is the Bitbucket workspace (or GitLab group) the token is scoped to, null elsewhere. `scopes` is what the token was found to grant at `last_verified_at` — an empty array means the check has not run or returned none, not that the token is scopeless.

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
{"storage_destinations": [{
  "id": 1,
  "name": "S3 Backup",
  "driver": "s3",
  "endpoint": "https://s3.eu-west-1.amazonaws.com",
  "region": "eu-west-1",
  "bucket": "my-backups",
  "prefix": "backups/",
  "has_credentials": true,
  "status": "connected", "status_title": "Connected",
  "last_tested_at": "28-07-2026 10:00:00", "last_tested_at_human": "yesterday",
  "last_test_success": true,
  "last_test_error": null,
  "created_at": "25-07-2026 14:30:00", "created_at_human": "2 weeks ago",
  "updated_at": "28-07-2026 10:00:00", "updated_at_human": "yesterday"
}]}
```

The field is `driver` (always `"s3"` today), not `provider`, and the path prefix is `prefix`, not `path_prefix`.

**`status` distinguishes three states, and the third one matters:** `never_tested` · `connected` · `failed`. `last_test_success` is deliberately nullable — never-asked is not the same as asked-and-failed, and rendering a red cross for an untested destination would be a lie. Drive the badge off `status`, not off the boolean.

**The test result is cleared whenever credentials or the address change.** A stored "connected" describes the keys that were tested, not the ones now saved; a green tick for a key rotated out ten seconds ago is the one thing this field exists to prevent.

`has_credentials` says whether an access key and secret are stored, without returning either. Keys are never in a response.

---

### POST `/integrations/storage/destinations`
**Permission:** `storage` (manage) | **Throttle:** 20/min

**Request:**
```json
{"name": "S3 Backup", "endpoint": "https://s3.amazonaws.com",
 "bucket": "my-backups", "region": "eu-west-1", "access_key": "AKIA…", "secret_key": "…", "prefix": "backups/"}
```

Required: `name` (unique, single-line), `bucket`, `access_key`, `secret_key`. Optional: `endpoint`, `region`, `prefix`.

**There is no `provider` field and no local-disk driver** — S3-compatible storage is the only option today, and `driver` is a read-only output field. `endpoint` may be omitted for AWS itself and for any provider that carries its region in the bucket host (R2, B2, Wasabi); when sent it must be an `https` URL, and it is refused if it resolves to loopback or the cloud metadata range (the same SSRF rule self-hosted Git providers get). `region` defaults to `us-east-1` when omitted or empty. `bucket` is `[A-Za-z0-9._-]`, `region` is `[A-Za-z0-9-]`, `prefix` is `[A-Za-z0-9._/-]`.

**Response `201`:** `{"storage_destination": {...}}`

---

### GET `/integrations/storage/destinations/{storageDestination}`
**Permission:** `storage` (view)

```json
{"storage_destination": {"id": 1, "name": "S3 Backup", "driver": "s3", "bucket": "my-backups", …}}
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

### GET `/basic-info`
Unauthenticated. The bootstrap call — safe to make before anyone is logged in, and the thing that decides whether the app shows a login form or a first-run registration form.

```json
{"basic_info": {
  "registration_open": false,
  "app_version": "1.0.0",
  "locales_available": ["en", "es", "de", "fr", "pt", "ja", "ru", "hi"],
  "cookie_auth_enabled": true
}}
```

`registration_open` is `true` only while **no user exists at all** — it is the first-admin bootstrap, not an open sign-up. Once the first account is created it is `false` forever and `POST /auth/register` stops accepting.

---

### GET `/timezones`
Unauthenticated.

```json
{"timezones": [{"value": "UTC", "label": "UTC"}, {"value": "Europe/London", "label": "London (GMT+1)"}, …]}
```

---

### GET `/health`
Unauthenticated. Health check for load balancers / uptime monitors. It has its own `60/min` limiter and does not consume the authenticated API polling bucket.

**Response `200`:** `{"health": {"status": "ok", "version": "1.0.14"}}`

`version` is the version actually detected for the running checkout and may be `null` when it cannot be established. Panel updates use it after a release swap to verify that the new code is answering.

---

## Central Management Connection

Lets a central panel drive this OSS install over `Authorization: Bearer <token>`. These three endpoints are the OSS admin's own settings screen and need a normal Sanctum session — they are not the central-facing API.

### POST `/central/enable`
**Auth:** administrator session

Generates a token, replacing any existing one. Enabling again rotates it and invalidates the old one.

**Response `201`:** `{"central_token": "sv_central_…", "message": "…"}`

`central_token` is returned in full **only in this creation response**, so the frontend can copy it into Central or pass it to an installer. It is never returned again: `GET /central/status` stays masked.

### GET `/central/status`
**Auth:** administrator session

**Response `200`:** `{"central": {"enabled": true, "token": "sv_central_a***************"}}`

`token` is `null` when `enabled` is `false`. Never the raw value.

### DELETE `/central`
**Auth:** administrator session

Revokes the current token. **Response `200`:** `{"message": "…"}`

---

## Incoming Deploy Webhooks

### POST `/webhooks/deploy/{identifier}`
**Unauthenticated by design.** This is the URL GitHub/GitLab/Bitbucket call; they carry no session or token, so the HMAC signature over the raw body is the credential. Configure it with `PUT /applications/{application}/webhook`, which returns the `identifier` for the path.

Not a frontend endpoint — documented so it isn't mistaken for a gap. The general `throttle:api` guest limiter is deliberately removed (a provider delivers from shared egress and would trip a per-IP limit); a limiter keyed on the webhook itself applies instead.

**Response `202`:** `{"deployed": true, "reason": null}` — accepted; a deploy may still be declined for a reason such as a non-matching branch, in which case `deployed` is `false` and `reason` says why.

**Response `401`:** `{"deployed": false, "reason": "invalid_signature"}`

A disabled webhook and an identifier that never existed both answer `404`, identically — anything else would confirm which applications exist.

---

## Admin API Error Logs

### GET `/admin/error-logs`
**Auth:** administrator session or token. Returns failed API requests and server operations from the rotating structured `server-ops` log; it is file-backed, not database-backed.

**Query:** `lines` integer, optional, `1–500` (default `100`); `reference` UUID, optional (exact lookup).

**Response `200`:** `{"error_logs":[{"occurred_at":"…","status":500,"method":"POST","route":"api/applications/{application}","exception":"…","message":"Unexpected API error.","reference":"…","user_id":1,"feature":null,"operation":null,"exit_code":null,"error":null}],"meta":{"truncated":false}}`

Server-operation records use `feature`, `operation`, `exit_code`, and a redacted, 1,000-character `error` summary to make a support reference actionable. The summary uses non-empty stderr first and falls back to stdout because installers and other CLI tools often report failures there; it remains `null` when neither stream contains output. API failures use the existing fields. Validation, authentication, authorization, and not-found responses are excluded. Entries never expose request bodies, credentials, cookies, tokens, SQL bindings, unredacted command output, or stack traces. The log rotates automatically and retains 30 days by default.

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
| `disk_cleaner` | cleaned, auto_cleaned, clean_failed, auto_clean_failed, schedule_updated |
| `cronjob` | created, updated, deleted, create_failed |
| `service` | started, stopped, restarted, reloaded, enabled, disabled, config_test |
| `fail2ban` | installed, updated, ban_added, ban_removed, all_bans_removed |
| `firewall` | rule_added, rule_updated, rule_removed, rule_enabled, rule_disabled, enabled, disabled |
| `git_account` | connected, updated, test_passed, test_failed, disconnected |
| `node` | install_started, uninstalled, npm_updated, default_changed |
| `setting` | updated, reboot_requested, reboot_scheduled, reboot_schedule_removed |
| `panel_update` | started, completed, failed |
