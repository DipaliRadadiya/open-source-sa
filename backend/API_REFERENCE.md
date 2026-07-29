# ServerAvatar OSS Backend — API Reference

> 🔗 **Live version:** <https://sv-oss.167-233-229-184.nip.io/docs/api-reference> (always current — bookmark it). Raw markdown: `/docs/api-reference.md`

Base URL: `https://sv-oss.167-233-229-184.nip.io/api`
Auth: `Authorization: Bearer <token>` header (returned by register/login), or cookie-session for stateful frontend domains (`SANCTUM_STATEFUL_DOMAINS`). Admin-only routes additionally require `is_admin: true`.
RBAC: `is_admin` (bool) gates the admin area only. Feature permissions are **pure role-based** — a user's effective permissions are the deduped OR-union across ALL their assigned roles (no direct per-user grants, no admin bypass). Every user has **≥1 role**. The first registered user is `is_admin` + the protected **Administrator** role (holds every permission, `is_system`, cannot be deleted/renamed/edited).
Errors: standard Laravel shape — `422` validation → `{"message": "...", "errors": {"field": ["..."]}}`; `401` unauthenticated; `403` forbidden (e.g. non-admin, or registration closed — note `403` has no `errors` key, only `message`); `429` rate-limited.
Envelope: every success response is wrapped under a **resource-named key** — singular for one record (`user`, `role`, `branding`, `basic_info`, `dashboard`), plural for lists (`users`, `roles`, `activity_log`, `permissions`), each paired with `meta` when paginated. There is no generic `data` wrapper.
Dates: all timestamps are `"DD-MM-YYYY HH:mm:ss"` strings, paired with a `_human` relative-time sibling (e.g. `"created_at": "24-07-2026 10:02:33"`, `"created_at_human": "2 minutes ago"`) — not ISO 8601.
Pagination: list endpoints accept `?per_page=10|20|50|100` and return `{"<resource>": [...], "meta": {"current_page", "per_page", "total", "last_page"}}`.

---

## Public (no auth)

### `GET /basic-info`
Pre-auth app state the frontend needs before login.
- Response: `{"basic_info": {"registration_open": bool, "app_version": string, "locales_available": string[], "cookie_auth_enabled": bool}}`
- `locales_available` currently `["en","es","de","fr","pt","ja","ru","hi"]` — every listed locale is fully translated (activity, auth, user, validation), so it's safe to drive a language switcher directly from this. Send the chosen one as `Accept-Language: <code>` on subsequent requests; validation/error messages come back in that language.

### `GET /branding`
White-label branding info.
- Response: `{"branding": {"name", "logo", "logo_dark", "icon", "icon_dark", "favicon", "primary_color"}}`

### `POST /auth/register`
Bootstrap-only — creates the **first** admin. Fails once any user exists (`registration_open` in `/basic-info` tells you when this is closed).
- Body: `name` (string, required), `username` (string, required, alpha_dash, unique), `password` (string, required, confirmed, min 10 + mixed case + numbers), `password_confirmation` (string, required, must match `password`)
- Rate limit: 5/min per username+IP
- Response `201`: `{"user": {id, name, username, is_admin, roles: [{id, name}], created_at, created_at_human}, "token": string}` — also sets a session cookie if the request came from a stateful domain. Registration-closed returns `403` (`{"message": ...}`, no `errors`).

### `POST /auth/login`
- Body: `username` (string, required), `password` (string, required)
- Rate limit: 5/min per username+IP
- Response `200`: same shape as register. `422` with `{"errors": {"username": ["These credentials do not match our records."]}}` on bad credentials.

---

## Authenticated (any logged-in user — `Authorization: Bearer <token>`)

### `POST /auth/logout`
Revokes the current token (or session, if cookie-authenticated). No body. Response `204`.

### `GET /auth/me`
Current user, plus impersonation state. Response: `{"user": {id, name, username, is_admin, roles: [{id, name}], created_at, created_at_human}, "impersonated_by": {id, username}|null}` — `impersonated_by` is non-null only during an impersonated session (show a banner); otherwise `null`.

### `PUT /auth/profile`
Self-service profile update (own `name` + `username`).
- Body: `name` (string, required, max 255), `username` (string, required, `alpha_dash`, max 255, unique — the caller's own current username is allowed).
- Response `200`: `{"user": { …same shape as `/auth/me` user… }}`. Writes a `user.profile_updated` activity entry.
- `422` on validation (e.g. username taken by another user); `401` if unauthenticated.

### `PUT /auth/password`
Self password change. Also revokes all existing tokens and issues a new one (so the caller must re-store the returned token).
- Body: `current_password` (string, required, must match), `password` (string, required, confirmed, min 10 + mixed case + numbers), `password_confirmation`
- Response `200`: `{"token": string}`

### `POST /auth/stop-impersonating`
Ends an impersonated session — re-logs in the original admin on the cookie session and clears the impersonator marker. Called on the impersonated session; a normal (non-impersonation) session returns `422`. Response `204`.

### `GET /activity-log`
The caller's **own** activity history only (not admin-wide — see `/admin/activity-log` for that). No `user` field per entry — it's always the caller, so it's omitted as redundant (unlike the admin version below, which spans multiple users and needs it).
- Query: `filter[type]` (exact), `filter[action]` (exact), `search` (free-text over `type` + `action`), `per_page` (10|20|50|100, default 10)
- Response: `{"activity_log": [{id, type, action, description, created_at, created_at_human}], "meta": {...}}` (`type` = entity, `action` = verb, `description` = composed sentence)
- **No `filter[user_id]`** — the endpoint is always scoped to the caller, so there is no user to choose. The self-scope is applied before any filter; no filter combination can surface another user's rows. `search` also does **not** match actor names here (every row is the caller) — unlike the admin version.

### `GET /activity-log/filters`
Dropdown options for the caller's own history. **Same response shape as `/admin/activity-log/filters`**, so the frontend can reuse one filter component with a different base URL.
- Response: `{"types": [...], "actions": {"all": [...], "<type>": [...]}}` — `actions.all` for the "any type" view, `actions.<type>` for a dependent dropdown.
- **Difference from the admin version:** these options come from the caller's **own rows** (DISTINCT), not from the full catalog in `lang/activity.php`. A user who has never touched a database is not offered a `database` filter that would match nothing. Admin lists everything that *can* exist; a personal history lists what actually happened.
- **A user with no activity gets `{"types": [], "actions": {"all": []}}`** — hide/disable the dropdowns rather than rendering an empty select.

### `GET /permissions`
Permission items the caller can see — the **deduped OR-union** across all their assigned roles (each permission appears once; `manage`/`view` are true if any role grants them). Pure role-based, no admin bypass: an admin sees everything only because they hold the Administrator role.
- Query: `level` (string, optional — filters to one permission level, e.g. `server`)
- Response: `{"permissions": [{level, sub_level, sub_level_title, name, title, icon, url, permissions: {view, manage}}]}`
- **Sidebar grouping:** group the items by `sub_level` and render `sub_level_title` as the section header (already localized — do **not** hardcode it). Current values: `server` → "Server", `integration` → "Integrations". A group is a **label only**: no route, no permission of its own. Show a group only when at least one item inside it came back, otherwise a limited role sees an empty header.
- **`title` is localized** to the request locale (send `Accept-Language: <code>`) — the sidebar label comes back already translated (8 locales). `name` is the stable machine key; use it if you'd rather translate client-side. A permission with no translation yet falls back to its English title. (Same for `/permissions/check` and `/admin/permissions`.)

### `GET /permissions/check`
Same as above but `level` is **required** (used for a targeted single-level check).
- Query: `level` (string, required)
- Response: same shape as `/permissions`

---

## Admin only (`is_admin: true`, plus `Authorization: Bearer <token>`)

### `GET /admin/dashboard`
Aggregate stats. No params.
- Response: `{"dashboard": {"users": {total, admins, non_admins}, "roles": {total}, "activity": {today, total}}}`

### Users — `GET|POST /admin/users`, `PUT|DELETE /admin/users/{user}`

**`GET /admin/users`** — list/search/filter
- Query: `search` (string, optional — matches `name` or `username`), `filter[is_admin]` (bool, optional), `per_page` (10|20|50|100)
- Response: `{"users": [{id, name, username, is_admin, roles: [{id, name}], created_at, created_at_human}], "meta": {...}}`

**`POST /admin/users`** — create
- Body: `name` (required), `username` (required, alpha_dash, unique), `password` + `password_confirmation` (required, min 10 + mixed case + numbers), `is_admin` (bool, required), `role_ids` (array, **required, min 1** — each must exist in `roles`; every user must have ≥1 role)
- Response `201`: `{"user": {id, name, username, is_admin, roles: [{id, name}], created_at, created_at_human}}`

**`PUT /admin/users/{user}`** — edit
- Path: `user` = user ID
- Body: `name` (required), `username` (required, alpha_dash, unique ignoring self), `is_admin` (bool, required). (Roles are managed via `.../roles` below.)
- Response `200`: same shape as create

**`DELETE /admin/users/{user}`** — delete
- Path: `user` = user ID
- Blocked (`422`) if `user` is the caller's own account. Revokes the deleted user's tokens.
- Response `204`

### `PUT /admin/users/{user}/reset-password`
- Path: `user` = user ID
- Body: `password` + `password_confirmation` (required, min 10 + mixed case + numbers)
- Response `204`

### `POST /admin/users/{user}/impersonate`
Admin "login as user" — **session-based** (no token). Logs the target in on the current Sanctum **cookie session** and marks the impersonator in the session; the target's own permissions still gate everything. Blocked (`422`) for self and for admin→admin.
- Path: `user` = user ID (must be a non-admin, not self)
- Response `201`: `{"user": {...target...}, "impersonated_by": {id, username}}` — **no token**.
- Frontend: nothing to store — the cookie session now *is* the target. `GET /auth/me` reports `impersonated_by` (show a banner); call `POST /auth/stop-impersonating` to switch back to the admin.

### `PUT /admin/users/{user}/roles`
Syncs the user's assigned roles (many-to-many).
- Path: `user` = user ID
- Body: `role_ids` (array, **required, min 1** — each must exist in `roles`; a user can hold multiple roles and can never be left with zero)
- Response `204`

### `GET /admin/permissions` — permission catalog (for the role form)
The **full** list of every permission (the menu of what *can* be granted) — use this to render the checkboxes in the role create/edit form. Distinct from `GET /permissions`, which returns only the **caller's own effective grants** (for the nav). Admin-only.
- Response: `{"permissions": [{level, sub_level, sub_level_title, name, title, icon, url}, …]}` — ordered by the catalog `order`; **no** `view`/`manage` state (that's per-role — overlay each role's own `permissions[]` from `GET /admin/roles` onto this list). Group client-side by `level`/`sub_level` for sections.

**`POST /admin/permissions/sync`** — re-sync the permission catalog from code (runs the seeder) and re-sync the protected **Administrator** role. Idempotent; a UI shortcut for the deploy-time seed, handy after new permissions are added. Admin-only.
- Response `200`: `{"permissions": [ …full catalog… ], "synced": <count>}`. Logged to the activity log as `permission.synced`.

### Roles — `GET|POST /admin/roles`, `PUT|DELETE /admin/roles/{role}`

**`GET /admin/roles`** — list (not paginated, returns all)
- Response: `{"roles": [{id, name, slug, is_system, description, permissions: [...], created_at, created_at_human}]}` — `is_system: true` marks protected roles (e.g. Administrator).

**`POST /admin/roles`** — create
- Body: `name` (required, string, max 255 — duplicates checked case-insensitively via normalized slug), `description` (nullable, string, max 1000), `permissions` (array, optional) — each item: `level`, `name`, `view`, `manage` (all required if `permissions` sent)
- Response `201`: `{"role": {id, name, slug, is_system, description, permissions: [{level, name, title, permissions: {view, manage}}], created_at, created_at_human}}`

**`PUT /admin/roles/{role}`** — update
- Path: `role` = role ID
- Body: same as create (name uniqueness ignores this role itself)
- Response `200`: same shape as create. **`422`** if the role is `is_system` (protected — Administrator can't be modified).

**`DELETE /admin/roles/{role}`** — delete
- Path: `role` = role ID
- Users holding this role simply have it detached (not deleted). **`422`** if the role is `is_system` (Administrator can't be deleted).
- Response `204`

### `GET /admin/activity-log`
Admin-wide activity — every user's actions, not just the caller's.
- Query: `filter[user_id]` (integer, must exist), `filter[type]` (string, exact — the entity, e.g. `user`/`role`/`system_user`), `filter[action]` (string, exact — the verb, e.g. `created`/`deleted`/`ssh_key_added`), `search` (free-text on type/action/actor name/username), `per_page` (10|20|50|100). `type` and `action` are separate **indexed** columns.
- Response: `{"activity_log": [{id, type, action, description, user: {id, username}|null, created_at, created_at_human}], "meta": {...}}` — `type` = entity (`system_user`), `action` = verb (`created`), `description` = the full human sentence composed from both in the viewer's locale.

### `GET /admin/activity-log/filters`
Distinct `type`/`action` values, for populating a frontend filter dropdown. Sourced from the known translation keys (`lang/activity.php`), not a `DISTINCT` query on actual log rows — so it's fully populated even on a fresh install with zero activity yet.
- Response: `{"types": string[], "actions": {"all": string[], "<type>": string[], ...}}` — `actions.all` is every verb (use on initial load / "any type"); `actions.<type>` is scoped to that type's verbs (use for a dependent action dropdown once a type is picked). No client-side merging needed.

---

## Server Panel (`Authorization: Bearer <token>`; each route gated by its feature permission)

Server-panel routes are **permission-gated** (pure role-based) — the caller needs the feature's `view`/`manage` grant via a role (admins have it through the Administrator role). Missing permission → `403`. Server operations that fail return **`500` with `{"message": "<translated>", "reference": "<uuid>"}`** — the reference correlates with the server-ops log; raw stderr never reaches the client.

### System Users — the Linux OS accounts that own/run sites
Requires `system_user` permission (`view` to read, `manage` to mutate). No update endpoint (username is fixed — delete + recreate).

**`GET /api/system-users`** — list
- Response: `{"system_users": [{id, username, home_path, shell, sudo, ssh_access, password, applications: [{id, name}], created_at, created_at_human}]}` — `applications` is **minimal (id + name)** on the list.

**`GET /api/system-users/{systemUser}`** — detail
- Response: `{"system_user": {id, username, home_path, shell, sudo, ssh_access, password, applications: [{id, name, domain, site_type, php_version, status}], created_at, created_at_human}}` — **full** applications on detail.
- `password` is the **plaintext OS password** (operator decision — stored so an admin can copy it for server login; `null` until one is set). `ssh_access` (bool) = whether the account is in the SSH allow-group.

**`POST /api/system-users`** — create (runs `useradd`)
- Body: `username` (required — Linux rules `^[a-z_][a-z0-9_-]{0,31}$`, not a reserved system name, unique), `public_key` (optional — a valid SSH public key added as the initial authorized key)
- Response `201`: `{"system_user": {...}}`

**`DELETE /api/system-users/{systemUser}`** — delete (runs `userdel -r`)
- **`422`** if it still owns ≥1 application (can't orphan apps).
- Its **cron jobs are also deleted** — each `/etc/cron.d` file is removed and the rows dropped (they'd otherwise point at a deleted user). The `system_user.deleted` activity entry records how many went with it in `properties.cronjobs_removed`.
- Response `204`

**`PUT /api/system-users/{systemUser}/password`** — set/change OS password (runs `chpasswd`)
- Body: `password` (required, min 10 + mixed case + numbers). Response `204`.
- The password is piped to `chpasswd` stdin (never in the command/log) **and** stored plaintext on the row so it can be shown/copied later (operator decision).

**`PUT /api/system-users/{systemUser}/sudo`** — grant/revoke sudo (`usermod -aG sudo` / `gpasswd -d`)
- Body: `sudo` (bool, required). Response `200`: `{"system_user": {...updated...}}`.

**`PUT /api/system-users/{systemUser}/shell`** — change login shell (`usermod -s`)
- Body: `shell` (required, one of `/bin/bash`, `/bin/sh`, `/usr/bin/zsh`, `/usr/sbin/nologin`, `/bin/false`). Response `200`: `{"system_user": {...updated...}}`.

**`PUT /api/system-users/{systemUser}/ssh`** — enable/disable SSH login (`usermod -aG ssh-users` / `gpasswd -d`)
- Body: `ssh_access` (bool, required). Response `200`: `{"system_user": {...updated...}}`.
- Toggles membership of the `ssh-users` group; only enforces login when `sshd_config` carries `AllowGroups ssh-users` (set by server provisioning).

The system_user object also includes `sudo` (bool), `ssh_access` (bool), and `password` (plaintext, nullable).

### SSH keys — nested sub-resource of a system user
**`GET /api/system-users/{systemUser}/ssh-keys`** → `{"ssh_keys": [{id, name, fingerprint, created_at, created_at_human}]}`
**`POST /api/system-users/{systemUser}/ssh-keys`** — add
- Body: `name` (required), `public_key` (required, valid SSH key; duplicate of an existing key → `422`)
- Response `201`: `{"ssh_key": {id, name, fingerprint, ...}}`. Rewrites the user's `authorized_keys`.
**`DELETE /api/system-users/{systemUser}/ssh-keys/{sshKey}`** → `204`. Rewrites `authorized_keys`.

### Cron jobs
Requires the `cronjob` permission (`view` to read, `manage` to mutate). All routes are under `auth:sanctum`. Each job runs **as an OS user** — a panel System User **or** a default/unmanaged account (`root`, `www-data`, …). Behind the scenes a job is one file under `/etc/cron.d/<slug>` (non-destructive; only `active` jobs are on disk) — the frontend never deals with files, only the JSON below.

#### The cron job object
Every single/`cronjob` and list/`cronjobs[]` entry has this exact shape:
```json
{
  "id": 12,
  "name": "Nightly backup",
  "slug": "nightly-backup",
  "username": "deploy",
  "system_user": { "id": 3, "username": "deploy" },
  "command": "php /home/deploy/myapp/artisan schedule:run",
  "expression": "0 0 * * *",
  "active": true,
  "timezone": "Asia/Kolkata",
  "next_run_at": "30-07-2026 03:00:00",
  "next_run_at_human": "in 12 hours",
  "created_at": "25-07-2026 18:05:10",
  "created_at_human": "2 minutes ago"
}
```
- `slug` — stable, unique, auto-derived from `name`; safe to use as a React key / URL segment (survives data migration, unlike `id`).
- `system_user` — `{id, username}` when the run-as user is a panel System User, else **`null`** (a default OS user like `root`/`www-data`). `username` is always present regardless.
- `timezone` — the **server's** timezone. Cron interprets schedules against the OS clock, so `next_run_at` is computed there, not in UTC. Show it next to the time so a user in another timezone isn't misled.
- `next_run_at` — exact, computed from the expression. **`null` when `active` is false** (an inactive job has no next run).
- **There is no "last run" field, by design.** Linux cron keeps no record of actual executions, so any value we could return would be a *scheduled* time that stays populated even when the job never ran (server off, command crashed) — it would be read as proof of execution and lie. Real run history (exit code, duration, output) requires wrapping each command in a runner and is a separate, future feature.

#### List — `GET /api/cronjobs`
- Query params (all optional): `filter[system_user_id]` (int), `filter[username]` (string, exact), `filter[active]` (`true`/`false`), `per_page` (`10`|`20`|`50`|`100`, default `10`), `page` (int).
- `200`:
```json
{
  "cronjobs": [ /* cron job objects */ ],
  "meta": { "current_page": 1, "per_page": 10, "total": 3, "last_page": 1 }
}
```

#### Show — `GET /api/cronjobs/{id}`
- `200`: `{ "cronjob": { /* cron job object */ } }`

#### Create — `POST /api/cronjobs`
Request body:
```json
{
  "name": "Nightly backup",
  "system_user_id": 3,          // OR "username": "www-data" — one is required
  "command": "php /home/deploy/myapp/artisan schedule:run",
  "expression": "0 0 * * *",
  "active": true                 // optional, default true
}
```
Field rules (all validation errors are `422` with `{ "message": "...", "errors": { "<field>": ["..."] } }`):
| Field | Rules |
|---|---|
| `name` | required · unique · no line breaks · not a reserved system name (`php`, `certbot`, …) |
| `system_user_id` | required **without** `username`; must exist in `system_users` |
| `username` | required **without** `system_user_id`; Linux name `^[a-z_][a-z0-9_-]{0,31}$`; **must exist on the server** (checked via `getent passwd`) |
| `command` | required · max 1000 · no line breaks · must **not** contain an unresolved `{path}` |
| `expression` | required · valid 5-field cron (or a macro like `@daily`) |
| `active` | optional boolean |
- `201`: `{ "cronjob": { /* cron job object */ } }`
- On a failed server write (rare): `500` `{ "message": "...", "reference": "<uuid>" }` — nothing is persisted (DB rolled back).

#### Update — `PUT /api/cronjobs/{id}`
- Body: any of `name`, `command`, `expression`, `active` (same rules as create; `sometimes`). The **run-as user is fixed** — to change it, delete and recreate.
- `200`: `{ "cronjob": { /* cron job object */ } }`

#### Delete — `DELETE /api/cronjobs/{id}`
- `204` (no body). Also removes the cron.d file.

---

#### Building the create form (shortcuts)

**1. Schedule dropdown** — `GET /api/cronjobs/schedule-presets`
```json
{ "presets": [
  { "key": "every_minute", "label": "Every minute", "expression": "* * * * *" },
  { "key": "hourly",       "label": "Hourly",       "expression": "0 * * * *" },
  { "key": "daily",        "label": "Daily (midnight)", "expression": "0 0 * * *" },
  { "key": "custom",       "label": "Custom",        "expression": null }
] }
```
Render each as an option; on select, set the form's `expression` to `preset.expression`. `custom` → `expression: null` → show a free-text cron field. Labels are localized to the request's `Accept-Language`.

**2. Framework command dropdown** — `GET /api/cronjobs/command-presets`
```json
{
  "placeholder": "{path}",
  "presets": [
    { "key": "laravel",   "label": "Laravel Scheduler", "command": "php {path}/artisan schedule:run", "expression": "* * * * *" },
    { "key": "wordpress", "label": "WordPress Cron",     "command": "php {path}/wp-cron.php",          "expression": "*/5 * * * *" },
    { "key": "custom",    "label": "Custom",             "command": null,                              "expression": null }
  ]
}
```
On select, set the form's `command` to `preset.command` **and** `expression` to `preset.expression`. Keys: `laravel, wordpress, moodle, joomla, nextcloud, craftcms, php_script, custom`.

**3. Resolve `{path}` before submitting.** Preset commands are templates containing the `placeholder` token (`{path}`). Replace it with the **absolute directory of the app/site** the job runs in (e.g. `/home/deploy/myapp`):
```js
const command = preset.command.replaceAll(res.placeholder, app.path);
// "php {path}/artisan schedule:run" → "php /home/deploy/myapp/artisan schedule:run"
```
Get that directory from the app the user picks (or a path field they type). **Do not submit a command still containing `{path}`** — the API rejects it with `422` on `command`. (Server-side app selection that auto-fills the path will arrive with the Application feature.)

On any OS-op failure → `500 {message, reference}` and the DB change is rolled back (no DB↔disk drift).

### Firewall
Requires the `firewall` permission (`view` to read, `manage` to mutate). Backed by **UFW**; the DB is the record, UFW the enforcement. Rules are **create/delete only** (edit = delete + re-add). Enabled/disabled is read **live** from `ufw status` (no stored flag).

**`GET /api/firewall`** — status + rules.
- Response: `{"enabled": bool, "default_policy": {"incoming": "deny", "outgoing": "allow"}, "rules": [ …rule objects… ]}`.
- Rule object: `{id, port_from, port_to|null, protocol, action, source_ip|null, description|null, origin, protected, summary, created_at, created_at_human}`. `origin` = `user`|`default`|`db_user`; `protected: true` = system-seeded (UI shows a lock, can't delete while enabled). `summary` = localized plain-English row, e.g. `"Allow 443/tcp from Anywhere"`.

**`GET /api/firewall/presets`** — common-service shortcuts for the dropdown.
- Response: `{"presets": [{key, label, port, protocol}, …]}` — `custom` has `port: null` (UI shows raw fields). Keys: `ssh, http, https, mysql, postgresql, redis, ftp, smtp, dns, custom`. Localized labels.

**`POST /api/firewall/rules`** — add a rule (applied to UFW immediately; takes effect when the firewall is on).
- Body: `port_from` (required, 1–65534), `port_to` (optional, range end, ≥ `port_from`), `protocol` (required, `all`|`tcp`|`udp`), `action` (required, `allow`|`deny`), `source_ip` (optional — IPv4/IPv6 or **CIDR**; blank = anywhere), `description` (optional). Duplicate (same port/proto/action/source) → `422`.
- Response `201`: `{"rule": { …rule object… }}` (`origin: "user"`).

**`DELETE /api/firewall/rules/{firewallRule}`** → `204`. Removes it from UFW. A **protected** (`origin != user`) rule can't be deleted while the firewall is **enabled** → `422` (lockout guard).

**`PUT /api/firewall/toggle`** — enable/disable UFW.
- Body: `enabled` (bool). **Enabling** seeds default `allow` rules (SSH + the configured web/panel ports: `22, 80, 443, …`) so the box is never locked out, sets the secure **default policy** (`deny incoming` / `allow outgoing`), then turns UFW on. **Disabling keeps** all rules (re-enable restores them).
- Response `200`: `{"enabled": bool, "default_policy": {...}}`.

OS-op failures → `500 {message, reference}`.

### Services
Requires the `service` permission (`view` to read, `manage` to act). Manages **systemd** units — the catalog is derived from our supported type sets (web server / database / cache / worker) + auto-detected **php-fpm** versions; **only installed units are surfaced**. No DB — status is read **live** from systemctl (detect-don't-trust).

**`GET /api/services`** — managed + installed services with live status.
- Response: `{"services": [{key, label, unit, status, enabled, protected, actions}, …]}`.
  - `status`: `active | inactive | failed`; `enabled`: bool (starts on boot).
  - `protected: true` = the panel's own web server / php-fpm → can't be stopped/disabled (lock icon).
  - `actions`: the allowed actions for *this* service (protected ones omit `stop`/`disable`) — render buttons directly from this.

**`PUT /api/services/{service}`** — run an action. `{service}` = the `key`.
- Body: `action` ∈ `start | stop | restart | reload | enable | disable`.
- Response `200`: `{"service": { …refreshed service object… }}`.
- `404` if the key is unknown or not installed; `422` if the action is blocked for a **protected** service (`stop`/`disable`) or the action is invalid; `500 {message, reference}` on systemctl failure.

### Logs
Requires the `logs` permission (`view`). **Read-only.** No DB — the catalog is a fixed source registry (web server / database / system / security / daemon) + auto-detected **php-fpm** logs, filtered to files that **actually exist** on the box (detect-don't-trust). The client only ever references a source by its **`key`**; the panel resolves the real path server-side (no client paths → no traversal).

**`GET /api/logs`** — detected log sources with metadata.
- Response: `{"logs": [{key, label, group, size, modified, readable}, …]}`.
  - `group`: `web | database | php | system | security | daemon` (for section headings).
  - `size`: bytes; `modified`: `DD-MM-YYYY HH:mm:ss`.
  - `readable: false` = the file exists but the panel process can't read it (needs a privileged read — Phase 2). Disable the open action in the UI.

**`GET /api/logs/{key}`** — read one source. `{key}` = the source `key`.
- Query (all optional): `lines` (last N lines, default `200`, max `5000`); `grep` (case-insensitive **literal** filter — returns the last N matching lines); `after` (byte **cursor** for incremental follow — poll with the previous response's `cursor` to fetch only newly-appended lines; rotation-safe).
- Response `200`: `{"log": {key, label, group, lines: [string, …], cursor, truncated}}`.
  - `cursor`: current byte size — pass back as `after` on the next poll for live-tail.
  - `truncated: true` = there was more content than returned (older lines above the window / capped).
- `404` if the key is unknown or the file doesn't exist; `403 {message}` if the file exists but isn't readable by the panel.

**`GET /api/logs/{key}/download`** — stream the full file as a download (`{key}.log`). Records a `log.downloaded` activity entry. `404`/`403` as above.

#### Frontend implementation guide — Logs page (UX + how it maps to this API)
> Researched against PatternFly's log-viewer guidelines, `open-log-viewer`, and common panel viewers (RunCloud/Forge). Everything below is buildable with **only the three endpoints above** — no extra backend work.

**Layout (recommended): two-pane.**
- **Left rail — source picker.** Render `GET /api/logs` grouped by `group` (headings: Web, Database, PHP, System, Security, Daemon). Each item shows `label`, a muted `size` (human-format the bytes client-side) and relative `modified`. If `readable === false`, show the item **disabled with a lock icon + tooltip** ("Needs elevated access") — don't let it open (the API returns `403`). This is the whole progressive-disclosure story: users see only sources that exist on *their* box.
- **Right pane — viewer.** Monospace, dark canvas, **line numbers**, one line per array item from `log.lines`. Use a **virtualized list** (e.g. TanStack Virtual / react-window) — never render 5 000 `<div>`s directly; that's the #1 perf killer for log UIs.

**Reading a source (initial load).** `GET /api/logs/{key}?lines=200` → render `log.lines`, stash `log.cursor`. If `log.truncated === true`, show a subtle top banner "Showing last 200 lines — [Load more]" ([Load more] just re-requests a bigger `lines`, e.g. 500 → 1000 → 5000-cap).

**Live tail (poll cursor — no websocket needed).**
```
let cursor = res.log.cursor            // from the initial load
setInterval(async () => {
  if (!follow) return                  // "Live" toggle is OFF → skip
  const r = await GET(`/api/logs/${key}?after=${cursor}`)
  if (r.log.lines.length) append(r.log.lines)
  cursor = r.log.cursor                // advance even if empty
}, 3000)                               // 3–5s is plenty; make it the poll interval
```
- The backend is **rotation-safe**: if the file was rotated (size < cursor) it transparently returns a fresh tail — just replace the buffer when you detect `r.log.cursor < cursor`.
- **"Live" toggle** (default ON for error/system logs, OFF for huge access logs). This is the standard tail on/off.

**Smart-sticky auto-scroll (the single most important log-UX detail).** Auto-scroll to bottom **only while the user is already at the bottom**. The moment they scroll up, *stop* auto-scrolling (they're reading history) and show a **"↓ Jump to latest" pill**; clicking it (or scrolling back to the bottom) re-arms auto-scroll. Standard recipe: `isAtBottom = scrollHeight - clientHeight - scrollTop <= 10`.

**Filter / search.** `?grep=<text>` = server-side **literal, case-insensitive** last-N-matching lines (cheap, scales to huge files — prefer it over client-side filtering). Debounce input ~300 ms. **Highlight** the matched substring in each rendered line. Show a **result count** ("42 matching lines") — key filtering-UX feedback. A client-side Ctrl/⌘+F "find in current view" is a nice complement for the already-loaded buffer.

**Severity coloring (client-side, purely cosmetic).** Tokenize each line and tint by level so errors pop: `ERROR`/`CRITICAL`/`FATAL` → red, `WARN` → amber, `INFO` → default, `DEBUG`/`NOTICE` → muted. For **access logs**, colorize by HTTP status (2xx green, 3xx blue, 4xx amber, 5xx red). The API returns raw lines — all parsing is presentation-layer; keep a small per-group regex set.

**Toolbar (top of viewer):** source label · **Live** toggle · lines selector (100/200/500/1000/5000) · search box (+ result count) · **Reload** · **Download** (`GET …/download` — browser handles the file) · **Wrap** (toggle line-wrap vs horizontal scroll) · **Clear view** (clears the *client* buffer only — Phase 1 has no server truncate).

**States to handle:** empty file → "This log is empty."; `403` → inline "Not readable by the panel yet" (mirror the `readable` flag); `404` → "Log no longer available" (re-fetch catalog); network error mid-poll → pause tailing + retry badge, don't spam.

**A11y / perf:** monospace font, line-height ~1.5, ≥14px; the scroll region is `role="log"` `aria-live="polite"` (only when following); throttle DOM appends to animation frames; cap the client buffer (e.g. last 10 000 lines) so long tailing sessions don't leak memory.

**Field → UI cheat-sheet:** `group`→section headings · `label`→display name · `size`/`modified`→list metadata · `readable`→lock/disable · `lines`→viewport rows · `cursor`→pass back as `after` for tailing · `truncated`→"load more" banner.

### Disk Cleaner
Requires the `disk_cleaner` permission (`view` to preview, `manage` to clean). **Server-level** cleanup (app-level logs/caches come later). No DB — disk usage + estimates are read **live** (detect-don't-trust). **Preview-then-clean**: the client selects **category keys only**; the panel resolves paths server-side (never a client path). Categories are detect-gated — only those whose dependency exists are returned.

**`GET /api/disk-cleaner`** — preview.
- Response: `{ disk: {path,total,used,free,percent, *_human}, categories: [{key,label,description,note,group,method,paths,safe,available,reclaimable,reclaimable_human}] }`.
  - `method`: `delete｜truncate｜command` — how it reclaims space (UI badge). `paths`: exactly what it touches (globs/dirs, or a friendly label for command-based ones) — show before the user confirms.
  - `note`: a short, localized plain-language line explaining **what happens and what is kept** when this category runs (e.g. "Empties the current service log files … services keep writing, nothing is deleted"). Show it as an info/tooltip on each category.
  - `group`: `package｜logs｜temp`. `reclaimable`: bytes.
- Phase-1 categories: `apt_cache`, `apt_orphans`, `journal`, `rotated_logs`, `service_logs` (truncates active service logs — kept, not deleted), `tmp`.

**`POST /api/disk-cleaner/clean`** — clean the selected categories (synchronous).
- Body: `categories` — non-empty array of keys ⊆ the available preview keys (whitelist; unknown/unavailable → `422`).
- Response `200`: `{ disk: {…refreshed…}, cleaned: [{key,freed,freed_human}], freed_total, freed_total_human }`.
- Writes a `disk_cleaner.cleaned` activity entry. `500 {message, reference}` on command failure.
- **Safety:** targeted commands only (no `rm -rf`); active logs are **truncated, not deleted**; whitelisted keys, server-resolved paths.

#### Automatic cleaner (schedule) — Phase 2
The schedule is a **DB profile** (single source of truth) run by the **Laravel scheduler** — there is **no cron file**, so it can never drift with the Cronjobs feature. Managed entirely here (not on the Cronjobs page).

**`GET /api/disk-cleaner/schedule`** — the profile (defaults when none set). `{ schedule: {enabled, frequency, categories, threshold_percent, notify, last_run_at, last_run_at_human} }`.

**`PUT /api/disk-cleaner/schedule`** (`manage`) — create/update the profile.
- Body: `enabled` (bool), `frequency` (`hourly｜daily｜weekly｜monthly`), `categories` (array of **safe** category keys), `threshold_percent` (1–100 or null = always), `notify` (bool).
- Runs unattended **safe-only** categories, **only when due AND** (if set) disk usage ≥ `threshold_percent`. Edit/disable takes effect on the next tick.
- Response `200`: `{ schedule: {…} }`. Writes `disk_cleaner.schedule_updated`.

**`DELETE /api/disk-cleaner/schedule`** (`manage`) — remove the schedule entirely → `204`.

**`GET /api/disk-cleaner/runs`** — run history (manual + scheduled), newest first, paginated. `{ runs: [{id, trigger, categories, freed, freed_total, freed_total_human, status, disk_percent, created_at, created_at_human}], meta }`.

### Settings
Requires the `setting` permission (`view` to read, `manage` to change). A server-config hub of **groups**; no DB — values are read **live** and changes are written to **managed non-destructive drop-ins** (the distro's own config is never touched → migration-safe). Groups are detect-gated (unavailable ones, e.g. Redis when not installed, are omitted).

**`GET /api/settings`** — all available groups + current values.
- `{ settings: { general:{timezone,ntp,hostname}, swap:{enabled,path,size,size_human,used,used_human,free,free_human}, security:{port,permit_root_login,password_authentication}, updates:{security_updates_enabled,auto_reboot,reboot_time,reboot_required}, redis?:{maxmemory,maxmemory_policy,has_password} } }`.
- `redis` omitted when redis-cli isn't installed. Passwords are never returned (`has_password` bool only).

**`PUT /api/settings/general`** (`manage`) — `timezone` (valid tz id), `hostname`, `ntp` (bool). Applies via `timedatectl`/`hostnamectl`.

**`PUT /api/settings/swap`** (`manage`) — manage a single **managed swap file**. `size_mb` (int, `0`–65536). `>0` creates/resizes idempotently (`swapoff` if active → `fallocate` → `chmod 600` → `mkswap` → `swapon` + a non-destructive `/etc/fstab` entry); `0` disables (swapoff + remove file + strip only our fstab line). Only the managed file is ever touched → migration-safe. Returns `{ swap: {…refreshed…} }`.

**`POST /api/settings/reboot`** (`manage`) — schedule a server reboot. `delay_minutes` (int, optional, `0`–60; default `0` = now) → `shutdown -r now|+N`. Response **`202`**: `{ reboot: { scheduled: true, when: "now"|"+5" } }`. Writes a `setting.reboot_requested` activity entry. `500 {message, reference}` if the OS command fails.

**`PUT /api/settings/security`** (`manage`) — SSH: `port` (1–65535), `permit_root_login` (`yes｜no｜prohibit-password`), `password_authentication` (bool). Writes an `sshd_config.d` drop-in, runs **`sshd -t` before reload**, **opens the new port in the firewall first** (if enabled). `422` if disabling password auth with **no SSH key present** (lockout guard).

**`PUT /api/settings/updates`** (`manage`) — `security_updates_enabled` (bool), `auto_reboot` (bool), `reboot_time` (`HH:MM` or `now`). Writes an `apt.conf.d` drop-in (unattended-upgrades).

**`PUT /api/settings/redis`** (`manage`) — `maxmemory` (`0` or `256mb`…), `maxmemory_policy` (enum), `password` (optional; only changed when provided). Via `redis-cli CONFIG SET`+`REWRITE`. `404` if redis isn't installed.

Each write returns `{ <group>: {…refreshed values…} }`, writes a `setting.updated` activity entry (`group` property), and returns `500 {message, reference}` on OS-command failure.

### Server Dashboard
Requires the `dashboard` permission (`view`). Read-only. Facts + live metrics are read cheaply from `/proc` (+ `df`) — no root, no storage; 24h history comes from a 5-min collector (`server_metrics`, pruned to 24h → bounded ~288 rows). Each concern is its own endpoint.

**`GET /api/server/facts`** — info card (changes rarely). `{ facts: {hostname, os, kernel, arch, uptime:{seconds,human}, ip, cpu:{model,cores}, memory_total(+_human), disk_total(+_human), timezone, reboot_required, runtimes:{php,node,nginx,redis,mysql}} }` (runtime = version or `null` if absent).

**`GET /api/server/metrics/live`** — current snapshot; **poll every ~2–5s** for the live gauges + the **Network in/out streaming chart**. Each resource is a full **total/used/free/percent** breakdown (bytes + `*_human`) so gauges can show "used of total, free":
```
{ "metrics": {
  "cpu":    { "percent": 12.5, "cores": 2 },
  "memory": { "total": 8192000000, "used": 4096000000, "free": 4096000000, "percent": 50, "total_human": "…", "used_human": "…", "free_human": "…" },
  "swap":   { "total": …, "used": …, "free": …, "percent": 25, … },
  "disk":   { "total": …, "used": …, "free": …, "percent": 60, … },
  "load":   { "1": 0.5, "5": 1.2, "15": 2.0 },
  "network":{ "in": 10240, "out": 5120, "in_human": "10 KB/s", "out_human": "5 KB/s" }   // bytes/sec
} }
```

**`GET /api/server/metrics/history`** — 24h series for the **CPU / Memory / Disk / Load** charts. `{ metrics: [{sampled_at, cpu, memory, swap, disk, load_1, load_5, load_15, net_in, net_out}, …] }` (5-min cadence).

**`GET /api/server/processes`** — server process table (top by CPU). `{ processes: [{pid, user, cpu, memory, command}, …] }`.

*(Deferred — Phase 3, with the Databases feature: `GET /api/server/database/metrics/history` (query/QPS chart) + `GET /api/server/database/processes` (DB process table + kill-query), engine-detected.)*

### Databases (P1)
Requires the `database` permission (`view` to read, `manage` to mutate). **3 engines** — `mysql | mariadb | mongodb` — via a `DatabaseEngine` strategy (SqlEngine covers mysql+mariadb, MongoEngine its own). Every op runs locally through the engine client with the admin creds in a 0600 auth file + statements over stdin (never a password on argv). A **DB user belongs to exactly one database** (nested resource). Identifiers are strict-regex validated (DDL can't be parameterised). Passwords are encrypted at rest but returned so you can build the connection string. `500 {message, reference}` on an engine failure.

**`GET /api/databases/engines`** — capability list: `{ engines: [{engine, driver, running, version, charsets}] }` (`running` = reachable with the configured connection).

**Admin connection** (per engine, config lives in the DB, not `.env`):
- **`GET /api/databases/connections`** → `{ connections: [{engine, driver, connection_type, host, port, socket, username, has_password, options}] }` (password never returned).
- **`PUT /api/databases/connections/{engine}`** (`manage`) — `connection_type` (`tcp|socket`), then `host`+`port` (tcp) or `socket` (socket), `username`, `password`, `options`, optional `test` (bool). → `{ <engine>: {…, reachable?} }`.
- **`POST /api/databases/connections/{engine}/test`** (`manage`) → `{ reachable: bool }`.

**Databases:**
- **`GET /api/databases`** → `{ databases: [{id, name, engine, driver, charset, collation, application_id, size_bytes, size_human, users_count, created_at(+_human)}] }`.
- **`POST /api/databases`** (`manage`) — `{name (regex ^[A-Za-z0-9_]{1,63}$, not a system schema), engine, charset?, collation? (must match charset), application_id?, create_user?: {username, password?, connection_preference, host?}}`. `201 { database: {…, users:[…]} }`. Omit `create_user` to add credentials later.
- **`GET /api/databases/{database}`** → `{ database: {…, users:[…]} }`.
- **`DELETE /api/databases/{database}`** (`manage`) — drops the DB + cascades its users (no orphans). `204`.

**Database users** (nested — belong to one database):
- **`GET /api/databases/{database}/users`** → `{ users: [{id, database_id, username, password, connection_preference, host, connection_string, created_at(+_human)}] }`.
- **`POST /api/databases/{database}/users`** (`manage`) — `{username, password? (auto-generated if omitted), connection_preference: localhost|remote|anywhere, host? (IPv4/CIDR, required when remote)}`. `remote`/`anywhere` opens the engine port in the firewall. `201 { user: {…} }`.
- **`PUT /api/databases/{database}/users/{user}/password`** (`manage`) — `{password}`. Engine `ALTER USER` then updates the stored credential. `{ user: {…} }`.
- **`DELETE /api/databases/{database}/users/{user}`** (`manage`) → `204`.

**Brownfield reconcile:**
- **`GET /api/databases/untracked?engine=`** → `{ untracked: [name, …] }` (server DBs not yet tracked, system schemas excluded).
- **`POST /api/databases/adopt`** (`manage`) — `{engine, names: [...]}` brings existing server DBs under management (never drops). `201 { databases: [...] }`.

**Edit a user (P2)** — **`PATCH /api/databases/{database}/users/{user}`** (`manage`) — any of `username`, `connection_preference`+`host` (remote toggle), `password`. SQL uses `RENAME USER` (grants preserved); Mongo drops+recreates with the password; firewall re-synced. `{ user: {…} }`.

**Monitoring + maintenance (P2):**
- **`GET /api/databases/processes?engine=`** — live process/op list: `{ processes: [{id, user, host, db, command, time, state, query}] }`.
- **`DELETE /api/databases/processes/{id}?engine=`** (`manage`) — kill a process/op (`KILL` / `killOp`). `204`.
- **`GET /api/databases/status/{engine}`** — health: `{ status: {connections, max_connections, threads_running, queries, slow_queries, uptime_seconds} }` (mongo returns nulls for SQL-only fields).
- **`GET /api/databases/metrics/history?engine=`** — 24h **Query Monitor** series: `{ metrics: [{sampled_at, qps, connections, threads_running}] }` (QPS = delta of the cumulative counter; 5-min `db:sample-metrics` collector, pruned to 24h).
- **`GET /api/databases/{database}/tables`** — structure peek: `{ tables: [{name, rows, size_bytes}] }` (no data browsing — that's phpMyAdmin).
- **`POST /api/databases/{database}/optimize`** and **`POST /api/databases/{database}/repair`** (`manage`) — `OPTIMIZE`/`REPAIR TABLE` across the DB's tables (SQL; no-op on Mongo). `{ database: {…} }`.

**Export (dump) — read-only, safe:**
- **`POST /api/databases/{database}/export`** — dumps the DB (`mysqldump --single-transaction` / `mongodump --archive --gzip`) to a managed exports dir. `201 { export: {file, size_bytes, created_at, download_url} }`. Source DB untouched; activity `database.exported`.
- **`GET /api/databases/exports/{file}`** — streams a previously-created export for download. Filename strict-validated + resolved inside the exports dir (no traversal).

*(Remaining P2: **import/restore** — deferred (writes data → will ship with existing-target-only + backup-before + confirm). P3: engine install-on-demand, app auto-DB + env-wiring, rename-database, phpMyAdmin signon SSO.)*

### Git integrations (Integrations → Git)

Requires the `git` permission (`view` to read, `manage` to mutate). Connected git provider accounts, managed **centrally and before any application exists** — the app-create wizard later just picks a connected account → repo → branch. This feature is panel-only: it stores a credential and reads repositories/branches. No cloning, no provisioning, no filesystem writes (those land with Applications).

**The token is write-only.** It is encrypted at rest and never returned by any endpoint — not even masked. To change it, send a new one via `PUT` (rotation).

**`GET /api/integrations/git/providers`** — the connect-form schema. Render the form from this rather than hardcoding per-provider fields; they genuinely differ.
- Response: `{ providers: [{name, title, token_help, fields: [{name, label, required, type}]}] }` — all strings already localized.
- Current field sets: **github** → `token` · **gitlab** → `token`, `host` (optional, self-hosted) · **bitbucket** → `workspace` (required), `token`.

**`GET /api/integrations/git/accounts`** — the connected accounts. Cheap DB read, no outbound calls — safe to use for a wizard dropdown.
- Response: `{ git_accounts: [{id, provider, provider_title, label, identifier, host, workspace, scopes, last_verified_at(+_human), created_at(+_human)}] }`
- `identifier` is what the provider calls the account — username for GitHub/GitLab, **workspace slug for Bitbucket**. It is fetched from the provider during verification, never typed by the user.

**`POST /api/integrations/git/accounts`** (`manage`) — connect. `{provider, label (unique), token, host? (gitlab only), workspace? (bitbucket, required)}`.
- The credential is **verified against the provider before anything is written** — a bad token returns `422` and stores nothing.
- `201 { git_account: {…} }`.
- `host` (self-hosted GitLab) must be `https://` and may not point at loopback or the cloud metadata range; private LAN addresses are allowed.

**`PUT /api/integrations/git/accounts/{account}`** (`manage`) — rename and/or rotate. Any of `label`, `token`, `host`, `workspace`. A changed credential is re-verified first, so a **rejected rotation leaves the previous working token intact**. `{ git_account: {…} }`.

**`POST /api/integrations/git/accounts/{account}/test`** (`manage`) — re-verify now; refreshes `identifier`, `scopes` and `last_verified_at`. `{ git_account: {…} }`.

**`DELETE /api/integrations/git/accounts/{account}`** (`manage`) — disconnect. `{ deleted: true }`.

**`GET /api/integrations/git/accounts/status`** — **live** token health for **all** connected accounts, one row each (not per-account — to check a single one, use its `test` endpoint). Nothing is cached or stored: a token can be revoked at the provider at any moment, so a persisted verdict would lie.
- Response: `{ statuses: [{id, label, provider, provider_title, status, status_title, expires_at, expires_in_days, checked_at}] }`
- `status` is one of **`valid`** (provider accepted it) · **`invalid`** (rejected — the user must act) · **`unknown`** (provider unreachable/timed out — **nobody should act**; do not render this as an error, a brief provider outage must not accuse a healthy token).
- Each row is independent: one dead account never breaks the others.
- **Call this in parallel with the accounts list, not instead of it.** The list paints instantly from the DB; the badges resolve when this answers. Deliberately kept out of the index so a wizard dropdown never waits on providers.
- `expires_at` availability differs by provider and this is not a bug: **GitLab** reports it (from `/personal_access_tokens/self`; a `read_repository`-only token falls back to a validity-only check with no expiry) · **GitHub** reports it when the token has one · **Bitbucket** Access Tokens have no expiry at all, so `null` means *there is none*, not *lookup failed*.

**`GET /api/integrations/git/accounts/{account}/repositories`** — `?search=&page=` → `{ repositories: [{full_name, name, private, default_branch, url}], meta: {page, has_more} }`. Only allow-listed fields are mapped out of the provider payload.

**`GET /api/integrations/git/accounts/{account}/branches`** — `?repository=owner/repo` (required) → `{ branches: [{name, protected}] }`.

**Bitbucket note for the UI:** Bitbucket uses **scoped Access Tokens** (workspace / project / repository level), not personal access tokens, and they authenticate *as the token* rather than as a user. A **repository-scoped token connects successfully and lists only that one repository** — that is the access the user granted, not an error. Say so in the UI rather than making it look like a failed fetch.

*(Deferred to Applications: `git clone`, deploy keys, webhook auto-deploy.)*

---

## Enums / fixed values

- `is_admin` (on `User`): boolean.
- Permission `level` (seeded so far): `"server"` — 14 items: `dashboard`, `application`, `database`, `system_user`, `firewall`, `cronjob`, `fail2ban`, `logs`, `service`, `setting`, `disk_cleaner`, `activity_log`, `git`, `storage`
- Permission `sub_level` (sidebar section): `"server"` (the first 12) · `"integration"` (`git`, `storage`). Render the header from `sub_level_title`, never from this raw value.
- Git provider (`GitAccount.provider`): `github | gitlab | bitbucket`
- Git token status: `valid | invalid | unknown`

---

## Known activity-log `type`/`action` values (for filtering)

Fetch these at runtime rather than hardcoding them — `GET /admin/activity-log/filters` (admin-wide, every value the system can record) or `GET /activity-log/filters` (the caller's own values only). Both return `{types: [...], actions: {all: [...], <type>: [...]}}`. For reference — `type` and `action` are separate values:
- `types` (12): `cronjob`, `database`, `disk_cleaner`, `firewall`, `git_account`, `log`, `permission`, `role`, `service`, `setting`, `system_user`, `user`
- `actions` (48 verbs, deduped across types): `cleaned`, `connected`, `connection_updated`, `create_failed`, `created`, `delete_failed`, `deleted`, `disabled`, `disconnected`, `downloaded`, `enabled`, `exported`, `impersonation_started`, `impersonation_stopped`, `imported`, `logged_in`, `optimized`, `password_changed`, `password_failed`, `password_reset`, `password_reset_by_admin`, `password_set`, `permissions_updated`, `process_killed`, `profile_updated`, `reboot_requested`, `registered`, `reloaded`, `repaired`, `restarted`, `role_assigned`, `rule_added`, `rule_removed`, `schedule_updated`, `shell_changed`, `ssh_disabled`, `ssh_enabled`, `ssh_key_added`, `ssh_key_removed`, `started`, `stopped`, `sudo_disabled`, `sudo_enabled`, `synced`, `updated`, `user_created`, `user_deleted`, `user_updated`
