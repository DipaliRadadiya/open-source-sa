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

### `GET /health`
Liveness probe. Unauthenticated **on purpose**: the panel self-update calls this on itself right after restarting services, at which point there is no session or token to present.
- Response: `{"health": {"status": "ok", "version": string|null}}`
- Rate limit: 60/min
- `version` is the running panel version, read from the `VERSION` file at the repository root (or `APP_VERSION` when pinned). `null` means neither was readable — reported honestly rather than guessing.
- Exposes nothing else. No commit, no paths, no counts.

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
- Query: `filter[scope]` (`account`|`server`), `filter[type]` (exact), `filter[action]` (exact), `search` (free-text over `type` + `action`), `per_page` (10|20|50|100, default 10)
- Response: `{"activity_log": [{id, type, action, scope, description, created_at, created_at_human}], "meta": {...}}` (`type` = entity, `action` = verb, `description` = composed sentence)
- **`scope`** answers one question per row: is this about the panel's people, or the machine? `account` = `user`, `role`, `permission` (logins, password changes, profile edits, impersonation, role grants). `server` = everything else (`cronjob`, `firewall`, `fail2ban`, `database`, `service`, `runtime`, `php`, `application`, `system_user`, `setting`, `disk_cleaner`, `git_account`, `log`, `server`).
- **Suggested wiring:** Account page tab → `filter[scope]=account` (a login history is what people expect there). Server sidebar Activity Log → no scope filter, with a scope chip — nobody wants to check two screens to answer "what happened to my panel today".
- `filter[scope]` **composes** with `filter[type]` and `search`; a contradiction (`scope=account&type=firewall`) correctly returns nothing. An unrecognised scope is a **`422`**, not an empty page — silently ignoring it looks like a broken filter, silently matching nothing looks like an empty history.
- **No `filter[user_id]`** — the endpoint is always scoped to the caller, so there is no user to choose. The self-scope is applied before any filter; no filter combination can surface another user's rows. `search` also does **not** match actor names here (every row is the caller) — unlike the admin version.

### `GET /activity-log/filters`
Dropdown options for the caller's own history. **Same response shape as `/admin/activity-log/filters`**, so the frontend can reuse one filter component with a different base URL.
- Response: `{"types": [...], "actions": {"all": [...], "<type>": [...]}, "scopes": [{"value": "account", "label": "Account"}]}` — `actions.all` for the "any type" view, `actions.<type>` for a dependent dropdown.
- **`scopes[].label` is already localized** to the request locale (8 languages). Do **not** hardcode "Account"/"Server" — same rule as `sub_level_title` on `/permissions`. Here too, only the scopes the caller actually has rows in are offered.
- **Difference from the admin version:** these options come from the caller's **own rows** (DISTINCT), not from the full catalog in `lang/activity.php`. A user who has never touched a database is not offered a `database` filter that would match nothing. Admin lists everything that *can* exist; a personal history lists what actually happened.
- **A user with no activity gets `{"types": [], "actions": {"all": []}}`** — hide/disable the dropdowns rather than rendering an empty select.

### `GET /timezones`
Every timezone the panel accepts, grouped by region — for a picker.

**Authenticated, but not permission-gated.** This is a reference list, not a resource: server settings, cronjob schedules and backup windows all need it, and gating it on any one of those permissions would hide it from the others.

- Query: none
- Response: `{"timezones": [{"region": "Asia", "zones": [{"value": "Asia/Kolkata", "label": "Kolkata", "offset": "+05:30", "offset_minutes": 330}]}]}`

**497 zones across 25 regions**, regions sorted, zones sorted by label within each — render in the order received.

- **`value`** is the IANA identifier and the only thing to send back (to `PUT /api/settings/general`, and later to schedule fields). **`label`** is the city with underscores replaced (`America/New_York` → `New York`) — what people actually scan for.
- **`offset` is the offset right now**, recomputed per request, so it stays right across DST rather than being frozen at deploy time. It costs under 10 ms for all 419, so there's no cache to go stale. Don't persist it; re-read the list rather than storing offsets.
- **`offset_minutes`** is there so you can sort or filter by offset without parsing the string. Note the half- and quarter-hour zones are real and common: `Asia/Kolkata` is 330, `Asia/Kathmandu` 345 — don't assume whole hours.
- **`UTC` is its own region** with a single zone. It has no region segment in its identifier, and it's the value a server is most likely already set to.
- The list comes from **the OS** (`timedatectl list-timezones`, 497 zones on this box), because the value is handed to `timedatectl set-timezone` — the OS decides what it accepts. `PUT /api/settings/general` validates against the same list, and a test pins the two together plus a third: **whatever `GET /api/settings` reports as the current timezone is always an accepted value.** That one exists because it wasn't: the list used to come from PHP, which omits the backward-compatible group, so `Etc/UTC` — what a fresh Debian box is set to — was shown by the form and rejected on save.
- To preselect the visitor's own zone, match `value` against `Intl.DateTimeFormat().resolvedOptions().timeZone` — it returns an IANA identifier from the same vocabulary.

### `GET /permissions`
Permission items the caller can see — the **deduped OR-union** across all their assigned roles (each permission appears once; `manage`/`view` are true if any role grants them). Pure role-based, no admin bypass: an admin sees everything only because they hold the Administrator role.
- Query: `level` (string, optional — filters to one permission level: `server` or `application`)
- Response: `{"permissions": [{level, sub_level, sub_level_title, name, title, icon, url, permissions: {view, manage}}]}`
- **There are two sidebars, and `level` is what selects them.** `?level=server` (17 items) renders the server sidebar; **`?level=application` (16 items) renders the sidebar shown *inside* an application**. Same shape, same rules — the permission row *is* the nav entry.
- **Application `url`s are relative segments**, not paths: `/domains`, `/files`, `''` for the dashboard. The real route is `/applications/{id}{url}` — prefix it client-side. Server `url`s stay absolute (`/databases`).
- Application permission names are all prefixed **`app_`** (`app_domain`, `app_log`, …). That is deliberate and load-bearing: ability checks resolve by name, so an app permission called `logs` would collide with the server-level one. Never assume `logs` and `app_log` are related — server `logs` is auth.log and syslog for the whole box, `app_log` is one site's access log.
- Some application permissions are seeded ahead of their screens (`app_staging`, `app_clone`, `app_fail2ban`, `app_firewall`, `app_bot_blocker`, `app_php`, `app_security`) so roles can be set up once. Render the sidebar from what this endpoint returns **and** what the application supports — a static site has no PHP settings regardless of grants.
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
- Query: `filter[scope]` (`account`|`server` — see `/activity-log` above for the split), `filter[user_id]` (integer, must exist), `filter[type]` (string, exact — the entity, e.g. `user`/`role`/`system_user`), `filter[action]` (string, exact — the verb, e.g. `created`/`deleted`/`ssh_key_added`), `search` (free-text on type/action/actor name/username), `per_page` (10|20|50|100). `type` and `action` are separate **indexed** columns.
- Response: `{"activity_log": [{id, type, action, scope, description, user: {id, username}|null, is_system, created_at, created_at_human}], "meta": {...}}` — `type` = entity (`system_user`), `action` = verb (`created`), `description` = the full human sentence composed from both in the viewer's locale.
- **`is_system`** (bool) — true when no person was behind the entry: a scheduled reboot, an automatic disk clean, a deploy triggered by a git webhook. `user` is `null` on those. **Render these as "System"** rather than a blank actor.
  - It's an explicit flag rather than something to infer from `user === null`, because "the machine did this" and "this row lost its user" would otherwise be indistinguishable. The panel deliberately does **not** stamp an admin's id onto system actions — that would name someone who wasn't there and put machine activity in their personal history.
  - Automatic actions get **their own verb**, not a flag on the manual one, so they're filterable: `disk_cleaner.auto_cleaned` vs `disk_cleaner.cleaned`, `setting.auto_rebooted` vs `setting.reboot_requested`.

### `GET /admin/activity-log/filters`
Distinct `type`/`action` values, for populating a frontend filter dropdown. Sourced from the known translation keys (`lang/activity.php`), not a `DISTINCT` query on actual log rows — so it's fully populated even on a fresh install with zero activity yet.
- Response: `{"types": string[], "actions": {"all": string[], "<type>": string[], ...}, "scopes": [{value, label}]}` — `actions.all` is every verb (use on initial load / "any type"); `actions.<type>` is scoped to that type's verbs (use for a dependent action dropdown once a type is picked). No client-side merging needed.
- **`scopes` always lists both** here, unlike the personal version — the admin log is the whole catalog, so an option with no rows behind it today is still the right option to offer. Labels are localized; don't hardcode them.

### `GET /admin/doctor`
Installation self-check. Runs each check **against the real server** — shells out to sudo and systemctl, reads the filesystem, calls the panel's own health endpoint over HTTP. Read-only: a check may never change the server to find out whether the server works.
- Rate limit: 10/min (it is a diagnostic, not something to poll)
- Response: `{"doctor": {"healthy": bool, "passed": int, "failed": int, "warnings": int, "checks": [{"key", "title", "status", "detail", "fix"}]}}`
- `status`: `pass` | `warn` | `fail`. **Warnings do not make an installation unhealthy** — `healthy` is false only when something *failed*.
- `title` and `fix` are localized; `detail` is deliberately **not** — it carries evidence (a version, a path, a unit name) for an operator, not prose for an end user. `fix` is null when the check passed.
- Checks, in order: `privilege`, `services`, `writable_paths`, `database`, `health_endpoint`.
- Same thing is available on the box as `php artisan panel:doctor` (add `--json` for this shape). It exits non-zero when unhealthy, and `install.sh` runs it at the end so the installer cannot report success on a panel that cannot work.

### Panel self-update — `GET|POST /admin/panel-update`, `GET /admin/panel-update/{panelUpdate}`

Updates the panel itself: fetches a published release, switches the installation to it, reinstalls dependencies, migrates, rebuilds the interface and restarts services. **The panel goes down for several minutes while this runs.** Hosted sites are unaffected — the web server serves them directly and the panel is not in their request path — so the only person who sees downtime is the admin watching the progress bar.

**`GET /admin/panel-update`** — state of play. Read-only; changes nothing.
- Query: `refresh` (bool, optional) — bypass the cached availability check for a "check now" button. Cached 60 min otherwise.
- Rate limit: 30/min
- Response:
```json
{"panel_update": {
  "installed": {"version": "0.1.0", "commit_hash": "b474320…", "commit_short": "b474320",
                "branch": "main"|null, "source": "file"|"env"|"unknown",
                "is_git_checkout": true, "has_local_changes": false|null},
  "available": {"version": "0.2.0"|null, "published_at": "2026-08-01T10:00:00Z"|null,
                "notes": "markdown"|null, "url": "https://…"|null, "checked": true},
  "update_available": false,
  "preflight": {"ready": false, "checks": [{"key": "git_checkout", "passed": true, "detail": null}, …]},
  "latest_run": {…PanelUpdate…}|null
}}
```
- **`available.checked: false` is normal, not an error.** It means the release host could not be reached (no outbound network, rate limit, host down). The call still returns `200` — an informational widget must not become a `500`.
- **`update_available` is `false` whenever either version is unknown.** Never prompt on a guess: accepting it starts a mutating operation with downtime.
- `preflight.checks` keys, in order: `git_checkout`, `clean_working_tree`, `free_disk`, `free_memory`, `writable_path`. Render `ready` as the button's enabled state and the failing `key`s as the reason. `clean_working_tree` **fails closed when unknown** — `git checkout --force` discards uncommitted work silently, so an unprovable tree is treated as dirty.
- `installed.branch` is `null` on an updated panel (checked out at a tag = detached HEAD). That's expected, not missing data.

**`POST /admin/panel-update`** — start it
- Query: `dry_run` (bool, optional) — runs the real script with every mutating command replaced by `echo`. Nothing is changed; the log at `<state_dir>/update-{id}.log` shows exactly what a real run would execute. **Use this first.**
- Rate limit: 3/min
- Response `202`: `{"panel_update": {…}}` — returns immediately. The runner is detached and the panel is about to restart itself, so there is nothing to wait on. Poll the status endpoint.
- `422` with `errors.version` when: an update is already in flight, the panel is already newest, preflight is not `ready`, or the published version failed validation.

**`GET /admin/panel-update/{panelUpdate}`** — poll progress
- Rate limit: 120/min (it is polled every couple of seconds; deliberately cheap, and it makes no outbound call)
- Response: `{"panel_update": {"id", "status", "status_title", "current_step", "current_step_title", "step_number", "total_steps", "from_version", "to_version", "from_commit", "to_commit", "reason", "reason_title", "rolled_back", "reference", "started_at", "started_at_human", "finished_at", "finished_at_human"}}`
- `status`: `pending` | `running` | `succeeded` | `failed`
- `current_step` (12 in order): `maintenance_on`, `backup_database`, `fetch_release`, `checkout_release`, `composer_install`, `migrate`, `seed_permissions`, `optimize`, `frontend_build`, `restart_services`, `maintenance_off`, `health_check` — plus `rollback` when recovering. Drive a progress bar from `step_number` / `total_steps` rather than hardcoding the list; show `current_step_title` (localized) as the label.
- On failure, `reason` is **the step that failed** (a stable key), and `reason_title` is the localized explanation. Raw stderr never reaches the client. `rolled_back: true` means the previous version was restored.
- ⚠️ **Expect the poll to fail mid-update.** `restart_services` restarts php-fpm, and `maintenance_on`…`maintenance_off` returns `503`. Treat connection errors and `503` during a run as *normal progress*, retry with backoff, and resume polling once the panel answers again — a finished update is reconstructed from the runner's state file, so the final status is correct even though nothing was alive to report it at the time.

### Central-panel connection — `GET|POST|DELETE /admin/central`

Connects this self-hosted panel to the vendor's central panel. The admin turns it on, copies the key **once**, and pastes it into the central panel, which then calls this panel's API with it.

The key carries **the same access an administrator has** — there is no scope picker and nothing partial to reason about. Everything else about the feature exists so that access is *consented to and revocable*: nothing is shared until an admin explicitly connects, the connection records who allowed it and when, and disconnecting deletes the token so the next request fails.

**`GET /admin/central`** — status
- Response when never connected: `{"central": {"connected": false}}`
- Otherwise: `{"central": {"connected": bool, "connected_at", "connected_at_human", "connected_by": {"id", "username"}, "last_used_at", "last_used_at_human", "revoked_at", "revoked_at_human"}}`
- After disconnecting this still returns the **last** connection with `connected: false` — "you connected this on the 4th and disconnected it on the 9th" is more useful than pretending it never happened.
- `last_used_at` is when the central panel last actually called in, `null` if it never has. This is the field that distinguishes a live integration from one switched on and forgotten.
- **The key is never in this response.** There is no endpoint that returns it again.

**`POST /admin/central`** — connect (issue the key)
- Rate limit: 5/min
- Response `201`: `{"central": {…}, "token": "1|abc…"}` — **the only time the token is ever returned.** Only its SHA-256 hash is stored, so a database leak does not leak a working key. Show it once, with a copy button, and say plainly that it cannot be shown again.
- `422` with `errors.central` when a connection is already live. Deliberately *not* a silent re-issue: that would kill a working integration on a stray click and nobody would connect the two events. To rotate the key, disconnect then connect.

**`DELETE /admin/central`** — disconnect (revoke)
- Response `204`. The token row is **deleted**, not flagged — there is no window in which a revoked key still works.
- `422` with `errors.central` when nothing is connected.

**The machine account.** The key belongs to a dedicated system account, not to the admin who pressed the button — otherwise deleting or demoting that person would silently kill the integration, and every action the central panel took would appear in the activity log under their name. That account is invisible to the admin area by design: it is not in `GET /admin/users`, not counted in `GET /admin/dashboard`, cannot log in, and every `/admin/users/{user}` route returns **404** for it. Frontend needs no special handling — it simply never appears.

**Activity.** `central.connected` and `central.disconnected` (scope `account`) record who granted and who withdrew access. That log — plus `last_used_at` — is the answer to "what did the vendor have access to, and when".

#### Building the screen

Exactly three states, decided by two fields:

| `central.connected` | `central.connected_at` | Screen |
|---|---|---|
| — (key absent) | — | **Never connected** — explanation + Connect button |
| `true` | set | **Connected** — connected by/when, last used, Disconnect button |
| `false` | set | **Disconnected** — same as never-connected, plus "last connected … , disconnected …" |

Concrete responses:

```jsonc
// GET /api/admin/central — never connected
{ "central": { "connected": false } }

// GET /api/admin/central — connected
{ "central": {
  "connected": true,
  "connected_at": "04-08-2026 12:19:07",
  "connected_at_human": "3 hours ago",
  "connected_by": { "id": 1, "username": "smit" },
  "last_used_at": "04-08-2026 15:02:44",   // null = the far end has never called
  "last_used_at_human": "4 minutes ago",
  "revoked_at": null,
  "revoked_at_human": null
} }

// POST /api/admin/central — 201. The ONLY response that ever carries the key.
{ "central": { "connected": true, "connected_at": "04-08-2026 12:19:07", … },
  "token": "17|Xq2f8yTn0pR4vB6cL9dK1sW3eG5hJ7mA" }

// POST while already connected — 422
{ "message": "This panel is already connected. Disconnect it first to issue a new key.",
  "errors": { "central": ["This panel is already connected. Disconnect it first to issue a new key."] } }
```

**The one thing that must not be got wrong:** `token` appears once, in the `POST` response, and no endpoint returns it again. Render it immediately in a copy-to-clipboard field with an explicit "this will not be shown again" line. If the user closes the dialog without copying, their only recovery is Disconnect → Connect, which invalidates the key they may already have pasted elsewhere — so say that on the dialog rather than letting them find out.

`DELETE` returns `204` with no body — refetch `GET /admin/central` after it rather than assuming the shape.

**Copy suggestion for the disconnect confirmation:** it stops the central panel reading or managing this server on its next request. It does not delete anything already collected there.

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
  "log_key": "cronjob_nightly-backup",
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
- **There is no "last run" field, by design.** Linux cron keeps no record of actual executions, so any value we could return would be a *scheduled* time that stays populated even when the job never ran (server off, command crashed) — it would be read as proof of execution and lie. What the job *actually* did is in its log instead (below), which records real output and a real exit code.
- `log_key` — key into the **Logs** endpoints for this job's captured output, or **`null`** when there is nothing to show yet. Open it with `GET /api/logs/{log_key}`, the same viewer and the same `after` polling cursor as every other log. Reading requires the `logs` permission.
  - Cron mails a job's output by default, and a server with no MTA discards it — so managed jobs redirect into one file per job. Each run appends the command's output followed by a status line:
    ```
    --- exit=0 at 2026-07-29T03:00:01+00:00
    ```
    That line is what tells "printed nothing" apart from "failed immediately". A non-zero `exit=` is the job failing.
  - `log_key` is `null` until the job has been written with output capture — a job created before this existed shows nothing until it is next saved. Show "no output captured yet" rather than an empty viewer.
  - Logs rotate automatically (daily, size-capped, compressed). Renaming a job carries its history over; deactivating one keeps it; deleting the job deletes it.

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

**`GET /api/firewall`** — status, rules, and the context needed to act on them.
- `your_ip` — the caller's own address. Offer it as a one-click **"only my IP"** source; without it people leave a port open to everyone rather than go and look their address up.
- `ssh_port` — the port SSH is really on, read here rather than from `/api/settings`. A firewall-only user calling Settings gets a `403` and would fall back to 22, and being wrong about the SSH port on this screen is how somebody locks themselves out.
- `listening[]` — `{port, protocol, address, public, program}`, what is actually behind the rules.
  - **`public`** is the field to lead with: bound to `0.0.0.0`/`::` a rule can expose it; bound to `127.0.0.1` no rule can. That distinction is most of the reason to show this.
  - **`program` is `null` for most entries.** The kernel only names a socket's process to that process's owner, and the panel is unprivileged — it will populate once the panel runs with more privilege. It is deliberately **not** inferred from the port number: a wrong service name on a firewall screen is worse than none.
  - A service bound to both IPv4 and IPv6 appears **once**.
- `risky_ports[]` — `{port, label, reason, installed}`. Derived from the engines actually on this server rather than a list in the UI, so the warning is about this machine. `installed: false` still warrants a warning — the port could be opened before the engine arrives.

**`PUT /api/firewall/rules/{firewallRule}`** (`manage`) — edit a rule, or switch it off.
- Body: any of `port_from`, `port_to`, `protocol`, `action`, `source_ip`, `description`, `enabled`.
- **`enabled: false` keeps the rule and removes it from UFW.** Testing whether a rule matters no longer means deleting it and hoping it gets retyped correctly — which, for a `deny` rule, means the thing it blocked is allowed in the meantime.
- **A description-only change never touches UFW.** Fixing a typo in a label does not go near a live firewall rule.
- When the rule itself changes, the **new one is added before the old is removed** — the other order leaves a window with neither in place, and a failed add would turn a `deny` into "allowed".
- Every rule now carries **`enabled`** in the list response.
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

### fail2ban

Watches logs for repeated failures and bans the source IP. Permission `fail2ban`; mutations need `manage`. No DB — live state comes from `fail2ban-client`, settings from a managed drop-in in `jail.d`.

**Independent of the Firewall feature by design.** Bans are not routed through UFW, so toggling the firewall off does not silently disable every ban. The two screens each tell the truth about themselves.

**`GET /api/fail2ban`** — everything the screen needs, in one call.
```json
{"fail2ban": {
  "installed": true, "running": true, "version": "1.0.2",
  "your_ip": "203.0.113.5",
  "settings": {"bantime": 3600, "findtime": 600, "maxretry": 5, "ignore_ips": ["203.0.113.5"]},
  "jails": [{"name": "sshd", "label": "SSH", "lockout_risk": true, "enabled": true,
             "banned": ["198.51.100.9"],
             "stats": {"currently_failed": 3, "total_failed": 1847,
                       "currently_banned": 2, "total_banned": 42}}],
  "banned": [{"ip": "198.51.100.9", "jail": "sshd",
              "banned_at": "2026-07-29 11:00:00", "expires_at": "2026-07-29 12:00:00",
              "seconds_left": 1800}],
  "bantime_presets": [{"key": "1h", "seconds": 3600, "label": "1 hour"}, …]
}}
```
- **`installed: false` is a normal state, not an error** — a fresh server has no fail2ban. `settings` is `null` and the lists are empty; render the install prompt, not an error.
- **`your_ip`** is the caller's own address. Offer it as a one-click addition to `ignore_ips` — this is what stops an operator banning themselves.
- `lockout_risk: true` marks a jail that can lock the operator out (i.e. `sshd`). Show the warning on that one.
- `stats` — **`currently_failed` is the headline number**: it means an attack is in progress *right now*, where the totals only say one happened at some point. `null` for a jail that isn't enabled.
- Each banned row carries **`expires_at` / `seconds_left`**, so the table can read "52 minutes left" rather than listing bare addresses with no way to tell whether to wait or unban. All three timing fields are **`null`** when the installed fail2ban is too old to report them, and when the ban is permanent — show "Permanent" for a null expiry on a live ban.
- `bantime_presets` — offer these instead of asking for seconds. `seconds: -1` is a permanent ban. Same backend-driven pattern as the cron schedule presets.

**`POST /api/fail2ban/install`** (`manage`) → `202`. Queued (apt is slow). **Nothing is enabled by the install** — a freshly installed fail2ban that started banning immediately would be a surprise. Poll `GET /api/fail2ban` until `installed` flips. `422` if already installed.

**`PUT /api/fail2ban`** (`manage`) — settings, ignore list and jail toggles together.
- Body: `bantime` (≥60s, or **`-1` for permanent**), `findtime` (≥30s), `maxretry` (≥2 — one failure is a typo), `ignore_ips[]` (IP or CIDR), `jails: {name: bool}`, `acknowledged` (bool).
- **One endpoint, not three**, because the values live in one file that is rewritten whole. For the same reason **a jail you omit keeps its current state** rather than switching off.
- **`422` `errors/fail2ban.lockout_risk`** when enabling a `lockout_risk` jail unless *either* the caller's IP is in `ignore_ips` *or* `acknowledged: true`. Show a confirm dialog offering to add `your_ip`.
- Loopback is always ignored and is not returned in `ignore_ips` — the user didn't add it and can't remove it.
- Applying **reloads** rather than restarts, so existing bans survive.

**`POST /api/fail2ban/bans`** (`manage`) — ban by hand. Body: `ip` (a single address, not a range), `jail`. `422` if the IP is on the ignore list (the ban would be dropped at the next reload).

**`DELETE /api/fail2ban/bans`** (`manage`) — release **every** ban. For the case this exists to serve — an office or VPN range banned by mistake — unbanning rows one at a time is not what the moment calls for. `404` when nothing is banned. → `{"unbanned": {"ips": [...]}}`

**`DELETE /api/fail2ban/bans/{ip}`** (`manage`) — release an address. Without `?jail=`, from **every** jail holding it. `404` if it isn't banned anywhere — refresh the list rather than showing success.

The fail2ban log is in the Logs feature as `fail2ban`, and the service itself is on the **Services** screen (so it can be restarted without SSH).

**The SSH jail follows the SSH port.** This panel can move SSH off 22; fail2ban's own default (`port = ssh`) would then ban a port nobody uses while reporting the server as protected. The port written into the jail is read from the same drop-in the Settings feature writes, so the two cannot drift apart.

OS-op failures → `500 {message, reference}`.

### Services
Requires the `service` permission (`view` to read, `manage` to act). Manages **systemd** units — the catalog is derived from our supported type sets (web server / database / cache / worker) + auto-detected **php-fpm** versions; **only installed units are surfaced**. No DB — status is read **live** from systemctl (detect-don't-trust).

**`GET /api/services`** — managed + installed services with live status.
- Response: `{"services": [{key, label, unit, status, enabled, protected, actions, testable, usage, log_keys}, …]}`.
  - `status`: `active | inactive | failed`; `enabled`: bool (starts on boot).
  - `protected: true` = the panel's own web server / php-fpm → can't be stopped/disabled (lock icon).
  - `actions`: the allowed actions for *this* service (protected ones omit `stop`/`disable`) — render buttons directly from this.
  - `testable`: whether this service can validate its own configuration. Show the **Test configuration** button only where this is true — a service with no meaningful test is not given an invented one.
  - `usage`: `{memory_bytes, memory_human, memory_percent, cpu_percent, tasks}`, or **`null`** for a stopped service or one where systemd accounting is off. Individual fields can also be `null`. **Render a null as `—`, never as `0`** — it means "not measured", which is a different fact.
    - Figures come from systemd's cgroup accounting, so a service's whole process tree is counted (a php-fpm master *and* all its workers).
    - `memory_percent` is of total system RAM. `cpu_percent` is of one core, so a service saturating two cores reads `200`.
    - **`cpu_percent` is null on the first read.** The underlying counter is cumulative since the service started, so a percentage needs two samples; each request stores one and the next measures against it. Poll `GET /services` on a timer (2–5s) and the value becomes "CPU used since the previous poll" — genuinely live. One `—` on first paint is expected.
  - `log_keys`: this service's log sources, as keys into the **Logs** endpoints below (e.g. `["nginx_error", "nginx_access"]`). Open the log viewer with one of these; there is no separate service-log endpoint, and `GET /logs/{key}`'s `after` cursor gives you a live tail by polling. Only sources that exist on the box are listed, so the array is empty rather than a button that opens nothing. Reading still requires the `logs` permission.

**`POST /api/services/{service}/config-test`** — validate the service's configuration. **Read-only: it never reloads.** Checking whether a change is safe is exactly what you do *before* applying it.
- Response `200`: `{"config_test": {"ok": true|false, "output": "…"}}`
- `output` is the tool's own message — it names the offending file and line. That describes the user's configuration, not panel internals, so it is returned in full and is the useful part of a failure.
- Per service: nginx `nginx -t` · apache `apachectl configtest` · **`php{version}-fpm` validates itself**.
- `404` unknown/not-installed service · `422` for a service with no configuration test.

**`PUT /api/services/{service}`** — run an action. `{service}` = the `key`.
- Body: `action` ∈ `start | stop | restart | reload | enable | disable`.
- Response `200`: `{"service": { …refreshed service object… }}`.
- `404` if the key is unknown or not installed; `422` if the action is blocked for a **protected** service (`stop`/`disable`) or the action is invalid; `500 {message, reference}` on systemctl failure.

### PHP
Requires the **`php`** permission (`view` / `manage`) — its own sidebar item, not a corner of Settings.

> **Moved.** These were previously split between `GET /api/php-versions/…` (gated by `service`) and `PUT|POST|DELETE /api/settings/php/…` (gated by `setting`), and the `php` group has been removed from `GET /api/settings`. Managing PHP used to need *both* permissions — and `setting` also grants the SSH port and the reboot button, so "can change the PHP version" implied "can reboot the server". All of it is now one feature behind one permission.

**`GET /api/php`** — everything the screen needs in one call.
```json
{"php": {
  "default": "8.4",              // what bare `php` resolves to (update-alternatives)
  "panel_version": "8.4",        // the version the panel itself runs on
  "versions": [{"version": "8.4", "path": "/usr/bin/php8.4", "is_default": true,
                "source": "apt", "in_use_by_panel": true, "in_use_by": 0,
                "service": "php8.4-fpm", "ini_path": "/etc/php/8.4/fpm/php.ini",
                "status": "ready", "started_at": null, "reason": null,
                "message": null, "reference": null},
               {"version": "8.3", "status": "installing",
                "started_at": "31-07-2026 05:20:11", "started_at_human": "2 minutes ago",
                "reason": null, "message": null, "reference": null}],
  "installable": [{"version": "8.5", "lifecycle": {"status": "active", "eol_date": "…"}}]
}}
```

**`status`** is `installing` | `ready` | `failed`, on every version row — one field to switch on.
- A version that is **installing has no other fields**: nothing is on disk yet, so there is no path, no ini and no FPM unit to report. This is the entry that did not exist before — versions are detected from the filesystem, so an in-flight apt run was invisible.
- **A version being installed is removed from `installable`**, so the install button can't start a second apt run for it.
- The row is written **before** the `202` returns, so polling immediately after `POST` always sees it.
- On **`failed`**: **`reason`** is a stable code — `package_not_found` | `apt_lock` | `network` | `no_space` | `worker` | `enable_failed` | `unknown` — switch on this, not on the text. Treat an unrecognised code as `unknown` rather than assuming the list is closed; it can grow. **`message`** is that reason as a sentence, localized to the *caller's* `Accept-Language`, so don't cache it across locales. **`reference`** locates the raw apt output in the server-ops log; the raw output is never returned, because it names internal paths and can't be translated.
- `worker` means the job died (timeout or a killed worker) rather than apt reporting anything. Without it a killed worker would leave the row spinning at `installing` forever.
- **A failed row persists until it's retried** — `POST` the same version again and it flips back to `installing` with `reason`/`reference` cleared. A successful install **deletes** the row and the version reappears from the filesystem as `ready`; `ready` is never stored, so it can't disagree with what's actually installed.
- **`in_use_by_panel`** → hide the remove control on that row; the API refuses it too.
- **`in_use_by`** is how many sites pin the version, and **`sites`** names up to five of them — "3 sites" doesn't tell you whether removing this breaks staging or the shop. **`sites_truncated`** is true when there are more; `in_use_by` is always the real total. The refusal message on `DELETE` names *every* site, uncapped.
- **`lifecycle`** is `{status, eol_date}` — `active` | `security` | `eol`. **There is no `lts` field for PHP**, because PHP has no LTS releases: active support, then security-only, then end of life. Sourced from endoflife.date by a daily scheduled command.
- **`lifecycle_available`** is false on a box with no outbound network or one that hasn't run the refresh. **Hide the badges entirely when it's false** — otherwise every version reads as unknown-and-therefore-suspect. Individual `lifecycle: null` means that one version isn't in the upstream data.
- **`installable`** is now `[{version, lifecycle}]`, not a flat string array, so the install picker can warn before someone installs something already dead.
- **`installable`** comes from the package index, so a box with the Ondřej archive sees the full range and one without sees only what its distro ships.
- **`service`** is the FPM unit. **Starting and stopping it stays on the Services screen** — that's the same job there as for nginx or redis, and it isn't the same thing as managing PHP. Link across using this key.

**`PUT /api/php/default`** (`manage`) — `{"default": "8.4"}` via `update-alternatives`. Only the CLI default moves; **a site keeps whatever version its FPM pool runs**. `422` if not installed.

**`POST /api/php/versions`** (`manage`) — `{"version": "8.3"}` → **`202`**, queued (apt takes minutes and holds a lock). Already installed returns `200`. Must be `major.minor`. Installs a usable PHP, not a bare interpreter: fpm, cli, common, mysql, curl, mbstring, xml, zip, gd, intl, bcmath, soap.

**`DELETE /api/php/versions/{version}`** (`manage`) → `204`. Three refusals, all `422`: **the version the panel runs on**, **a version a site pins** (named in the message), and **the current default**.

**`GET /api/php/versions/{version}/ini`** → `{"php_ini": {version, path, contents}}` — the raw file, for the editor.

**`PUT /api/php/versions/{version}/ini`** (`manage`) — replace it.
- Body: `contents` (the whole file) and **`acknowledged: true`**. A raw ini edit can stop PHP-FPM starting, so it must not be reachable by an accidental request.
- Sequence is **back up → write → `php-fpm{version} -t` → reload**. If PHP rejects it, **the previous file is restored and nothing is reloaded** — `422` with `errors/php.invalid_ini`. Only the edited version's unit is reloaded.
- `404` for a version that is not installed; the version is checked against the detected list before any path is built from it.

**`GET /api/php/versions/{version}/extensions`** (`view`)
```json
{"extensions": [
  {"name": "mysql", "package": "php8.4-mysql", "modules": ["mysqli","mysqlnd","pdo_mysql"],
   "installed": true, "enabled": true, "builtin": false, "sapis": {"cli": true, "fpm": true},
   "status": "ready", "started_at": null, "reason": null, "message": null, "reference": null},
  {"name": "redis", "package": "php8.4-redis", "modules": ["redis"],
   "installed": false, "enabled": false, "builtin": false, "sapis": {},
   "status": "installing", "started_at": "31-07-2026 05:20:11", "reason": null,
   "message": null, "reference": null},
  {"name": "json", "package": null, "modules": ["json"],
   "installed": true, "enabled": true, "builtin": true, "sapis": {},
   "status": "ready", "started_at": null, "reason": null, "message": null, "reference": null}
 ],
 "panel_required": ["curl", "mbstring", "..."]}
```
- **`status` is the state of the *operation*, not of the extension** — `installing` | `ready` | `failed`, with the same `reason` / `message` / `reference` fields as a version. `ready` means *nothing is in flight*, so `installed` and `enabled` can be trusted. A never-installed extension is therefore `ready`, not `failed`: if `status` meant installedness it would contradict `installed` on most rows.
- **`builtin` rows are always `ready`** — there is no package, so there is nothing that could be mid-install.
- Written before the `202` returns, same as versions, so the row is already `installing` when you re-read.
**96 rows, 32 installed, 16 built-in** on this box. Sorted installed-first — expect to need a search box.
- **A row is a package, not a module.** `php8.4-mysql` provides three. `enabled` is true only when *every* module is on in *every* SAPI — half-enabled behaves like off.
- **`sapis` is read-only**, showing drift from a manual `phpdismod`. The toggle always writes all SAPIs; splitting them lets a site work in a browser and fail in a cron deploy.
- **`builtin: true`** → render no control; the API refuses with `422`.
- **`panel_required`** is non-empty only for the panel's own version → disable those rows.

**`PUT /api/php/versions/{version}/extensions/{extension}`** (`manage`) — `{"enabled": true|false}`

| | |
|---|---|
| on, installed | `200 {extension}` — enabled in all SAPIs, FPM reloaded |
| on, not installed | **`202`** — apt queued; the row is already `status: "installing"`, poll until it leaves that state |
| off | `200 {extension}` — unlinked, FPM reloaded. **Never purged.** |
| built-in / panel-required / unknown | `422`, `422`, `404` |
| **on, not installed, on OpenLiteSpeed** | **`202`** — installing works normally (`apt install lsphp84-redis`). LiteSpeed's package puts its ini where LSPHP already reads it, so it is live after the restart. |
| **on/off for an *installed* extension, on OpenLiteSpeed** | **`422`** — there is no `phpenmod` for LSPHP and nothing to unlink an ini with, so installed and enabled are the same state. |

- **On OpenLiteSpeed, render "Install" but not a toggle.** An installed extension always reports `enabled: true`, because it genuinely is. Uninstalling is not offered on any web server — a disabled extension costs a few megabytes, and purging `php8.4-common` takes every site down with it.

- **`enable_failed`** is its own `reason`, distinct from a failed install: apt succeeded and `phpenmod` did not, so the package **is** installed. Offer "try again" — the retry takes the enable-only path and usually fixes it. Do not present it as "the install failed", which would send the user to redo work that is done.

**Nothing is ever purged** — a disabled extension costs a few megabytes; `apt purge php8.4-*` is how a server loses `php8.4-common` and every site with it. **The panel reloads FPM**, because `phpenmod` doesn't — it moves symlinks and stops there.

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

**`GET /api/disk-cleaner/schedule`** — the profile (defaults when none set). `{ schedule: {enabled, frequency, categories, threshold_percent, last_run_at, last_run_at_human} }`.

**`PUT /api/disk-cleaner/schedule`** (`manage`) — create/update the profile.
- Body: `enabled` (bool), `frequency` (`hourly｜daily｜weekly｜monthly`), `categories` (array of **safe** category keys), `threshold_percent` (1–100 or null = always).
- **`notify` is gone** (removed 2026-07-31). It was stored and echoed back but nothing ever read it — there is no notification or mail layer in the panel, and no email address to send to, since accounts are username-only. Render no toggle for it; a scheduled run records `disk_cleaner.cleaned` in the activity log, which is where "what did the cleaner do" is answered today.
- Runs unattended **safe-only** categories, **only when due AND** (if set) disk usage ≥ `threshold_percent`. Edit/disable takes effect on the next tick.
- Response `200`: `{ schedule: {…} }`. Writes `disk_cleaner.schedule_updated`.

**`DELETE /api/disk-cleaner/schedule`** (`manage`) — remove the schedule entirely → `204`.

**`GET /api/disk-cleaner/runs`** — run history (manual + scheduled), newest first, paginated. `{ runs: [{id, trigger, categories, freed, freed_total, freed_total_human, status, disk_percent, created_at, created_at_human}], meta }`.

### Settings
Requires the `setting` permission (`view` to read, `manage` to change). A server-config hub of **groups**; no DB — values are read **live** and changes are written to **managed non-destructive drop-ins** (the distro's own config is never touched → migration-safe). Groups are detect-gated (unavailable ones, e.g. Redis when not installed, are omitted).

**`GET /api/settings`** — all available groups + current values, plus who last changed each.
- `{ settings: {…}, last_changed: {…} }`.
- `settings`: `general:{timezone,ntp,clock_synchronized,hostname}`, `swap:{enabled,path,size,size_human,used,used_human,free,free_human}`, `security:{port,permit_root_login,password_authentication,has_ssh_key}`, `updates:{security_updates_enabled,auto_reboot,reboot_time,reboot_required,updates_available,security_updates_available,lists_refreshed_at,unattended_last_run_at,unattended_last_result}`, `redis?:{maxmemory,maxmemory_policy,has_password,password_manageable,running,memory_used,memory_used_human}`.
- `redis` omitted when redis-cli isn't installed. Passwords are never returned (`has_password` bool only).

**Read-only facts on the groups** — none of these are writable; they exist so each section can show state rather than an unlabelled toggle.

- **`updates.updates_available` / `security_updates_available`** (int|**null**) — from `apt-check`, the same source Ubuntu's MOTD uses (no apt lock, no network).
- **`updates.lists_refreshed_at`** — when `apt-get update` last **succeeded**. From `/var/lib/apt/periodic/update-success-stamp`, not the mtime of `/var/lib/apt/lists`, which also moves on failed runs.
- **`updates.unattended_last_run_at`** + **`unattended_last_result`** (`success｜failed｜null`) — parsed from the tail of the unattended-upgrades log, scoped to everything after the **last** start marker so an old error can't taint the current run. `unattended_last_result` is a **code, not a sentence** — the frontend owns the wording, as with runtime-install reasons.
- ⚠️ **`null` ≠ `0` here, and the difference is load-bearing.** `0` means "nothing is waiting"; `null` means "we could not find out" (`update-notifier-common` absent, log unreadable, command failed). Render them differently — a failed check drawn as `0` recreates the exact silent failure these fields exist to expose.
- **`security.has_ssh_key`** (bool) — whether any SSH key exists. This is *the same predicate* `PUT /api/settings/security` guards with, so use it to disable key-only login up front instead of accepting the choice and then returning `422`. One function, so the greyed-out control and the error can never disagree.
- **`general.clock_synchronized`** (bool) — whether the clock has actually reached a time server, which is **not** the same as `ntp` (the daemon being enabled). Enabled-but-not-syncing is silent: cron fires late and log timestamps drift, with nothing reporting a fault.
- **`redis.running`** (bool) + **`memory_used`** (bytes int|null) / **`memory_used_human`** — `running` comes from `PING`, so a `NOAUTH` reply still counts as up. The group is present whenever redis-cli is installed, so *installed but stopped* is a state you will see. Usage sits next to `maxmemory` because a limit alone tells the reader nothing.

**`last_changed`** — `{ "<group>": { user: {id, username}|null, at, at_human } }`.
- Keyed by group; **groups never changed are absent**, not null. `user` is null when the actor has since been deleted (the change still happened).
- A sibling of `settings`, not a field inside each group: the group maps are live OS state and are echoed verbatim by every `PUT`, and an actor is neither.
- Sourced from the existing `setting.updated` activity entries — nothing new is stored. `reboot_schedule` is found under its own verb (`setting.reboot_schedule_updated`), which carries no `group` property.

**`PUT /api/settings/general`** (`manage`) — `timezone` (valid tz id), `hostname`, `ntp` (bool). Applies via `timedatectl`/`hostnamectl`.

**`PUT /api/settings/swap`** (`manage`) — manage a single **managed swap file**. `size_mb` (int, `0`–65536). `>0` creates/resizes idempotently (`swapoff` if active → `fallocate` → `chmod 600` → `mkswap` → `swapon` + a non-destructive `/etc/fstab` entry); `0` disables (swapoff + remove file + strip only our fstab line). Only the managed file is ever touched → migration-safe. Returns `{ swap: {…refreshed…} }`.

**`POST /api/settings/reboot`** (`manage`) — schedule a server reboot. `delay_minutes` (int, optional, `0`–60; default `0` = now) → `shutdown -r now|+N`. Response **`202`**: `{ reboot: { scheduled: true, when: "now"|"+5" } }`. Writes a `setting.reboot_requested` activity entry. `500 {message, reference}` if the OS command fails.

**`PUT /api/settings/security`** (`manage`) — SSH: `port` (1–65535), `permit_root_login` (`yes｜no｜prohibit-password`), `password_authentication` (bool). Writes an `sshd_config.d` drop-in, runs **`sshd -t` before reload**, **opens the new port in the firewall first** (if enabled). `422` if disabling password auth with **no SSH key present** (lockout guard).

**`PUT /api/settings/updates`** (`manage`) — `security_updates_enabled` (bool), `auto_reboot` (bool), `reboot_time` (`HH:MM` or `now`). Writes an `apt.conf.d` drop-in (unattended-upgrades).


#### Redis

**`GET /api/settings/reboot-schedule/presets`** (`view`) — options for the dropdowns, **localized**, so nothing is hardcoded client-side.
```json
{"frequencies": [{"value": "daily", "label": "Daily"}, …],
 "hours": [{"value": 0, "label": "00:00"}, … 24 entries],
 "days_of_week": [{"value": 0, "label": "Sunday"}, …]}
```

**`PUT /api/settings/reboot-schedule`** (`manage`) — a plain scheduled reboot, whether or not an update asked for one.
- Body: `enabled` (bool), and when enabled `frequency` (`daily|weekly|monthly`), `hour` (0–23), plus `day_of_week` (0–6) for weekly or `day_of_month` (**1–28**) for monthly.
- **There is no free-form cron expression, deliberately.** Every other scheduling surface in the panel takes one; this restarts the machine, and `* * * * *` is a reboot loop nobody can log in to stop.
- **`day_of_month` caps at 28** so "monthly" happens twelve times a year — the 31st silently skips February and the short months.
- The reboot is scheduled a few minutes past the hour, not on it: every `:00` cron job fires on the same tick, and a reboot landing on a running backup is a half-written archive. (ServerAvatar's docs advise the same buffer.)
- Runs `shutdown -r +1`, not `reboot` — logged-in users get the wall message and services stop cleanly.
- Disabling **removes** the cron file rather than commenting it out; a disabled schedule left in `/etc/cron.d` is one uncomment away from a surprise restart.
- Read back in `GET /api/settings` as the **`reboot_schedule`** group: `{enabled, frequency, hour, day_of_week, day_of_month, timezone, next_run, next_run_human}`. It parses what is actually on disk, so a hand-edited file shows the truth.
- **`timezone` is the server's own**, because that's what cron uses — the same timezone set two fields up in General. No hidden UTC conversion, which is how a 3am window fires at 8am. `next_run` is computed from the written expression, so there is nothing to guess.

> **This is not the same as the `updates` auto-reboot.** That one is unattended-upgrades: it fires *only when a reboot is required* after a patch, and has no frequency at all. This one is a cadence, unconditional. Both exist because they answer different questions.

**`PUT /api/settings/redis`** (`manage`) — `maxmemory` (`0` or `256mb`…), `maxmemory_policy` (enum), and the password.
- **`password`** (string, min 8) sets a new one. **Omitting it leaves the current password alone** — the read side never returns it, so an unchanged form has nothing to send back.
- **`remove_password: true`** clears it. This needs its own flag because Laravel rewrites `""` to `null` before validation, making an empty password indistinguishable from an omitted one.
- **A password change returns `202`, not `200`**, and no `redis` body. It is applied **after this response is sent**, because the credential the panel is using is the one being replaced — the throttle middleware writes rate-limit headers to the cache after the controller returns but before the response is flushed, and by then Redis wants a password this process does not have. Poll `GET /api/settings` and read `has_password` to confirm. Memory settings still apply synchronously and return `200`.
- **`password_manageable`** (read) is false when the panel cannot write its own `.env` — **disable the control** rather than offering it and then refusing. Attempting it anyway is a `422` that names the reason, raised *before* Redis is touched.
- Changing the password also rewrites the panel's own `REDIS_PASSWORD`, so the two never drift. If the new credential cannot be verified, or cannot be recorded, **the old password is put back** and the failure is written to the `server-ops` log — nothing can reach the caller by then.
- The password is never returned; `read()` reports only `has_password`.


**`PUT /api/settings/general`** (`manage`) — `timezone`, `hostname`, `ntp`. **Applies only the fields that changed**: the form submits all three every time and they don't share a privilege level, so running an untouched one can fail the whole request *after* an earlier one already took effect. Re-saving an unedited form performs no OS command and cannot fail. Timezone values come from **`GET /api/timezones`**.

**`PUT /api/settings/updates`** also takes **`reboot_with_users`** (bool, optional). unattended-upgrades defaults this to *true*, which restarts the box under an administrator mid-SSH-session; omitting it here means **false**, so the surprising behaviour has to be chosen deliberately.

Each write returns `{ <group>: {…refreshed values…} }`, writes a `setting.updated` activity entry (`group` property), and returns `500 {message, reference}` on OS-command failure.

### Node.js
Requires the **`node`** permission (`view` / `manage`) — its own sidebar item, mirroring PHP.

> **Moved.** Was `PUT|POST|DELETE /api/settings/node/*` gated by `setting`, and the `node` group is gone from `GET /api/settings`. Same reason as PHP: `setting` also grants the SSH port and the reboot button, and Node needed a permission of its own before it could have a sidebar row. **Settings no longer has a Runtimes section** — neither runtime was ever a setting.

**`GET /api/node`**
```json
{"node": {
  "manager": "fnm",                      // fnm | system | none
  "default": "20.11.0",
  "versions": [{"version": "20.11.0",
                "path": "/opt/fnm/node-versions/v20.11.0/installation/bin/node",
                "is_default": true, "source": "fnm",
                "npm_version": "10.2.4",
                "in_use_by": 7, "sites": ["shop","blog","api","crm","docs"], "sites_truncated": true,
                "lifecycle": {"status": "lts", "eol_date": "2026-04-30", "lts_name": "Iron"}}],
  "system": {"version": "24.18.0", "path": "/usr/bin/node"},
  "installable": [{"version": "22.11.0", "lifecycle": {…}}],
  "lifecycle_available": true
}}
```
- **`npm_version`** is read from *that version's own* npm, not from whatever `npm` is on `PATH` — a global read reports the default version's npm next to every row, wrong for all but one. **`null`** when it can't be read; show nothing rather than a wrong number.
- **`lifecycle.status`** is `current` | `lts` | `maintenance` | `eol`, with **`lts_name`** (the codename, e.g. `Jod`) present for Node and `null` on lines that never become LTS. Sourced from **`nodejs/Release/schedule.json`**, the project's own file — **not** inferred from even-numbered majors, which is a convention rather than a rule.
- **`sites` / `sites_truncated` / `lifecycle_available`** behave exactly as on `GET /api/php`.
- **`status` / `started_at` / `reason` / `message` / `reference`** are on every version row and behave **exactly as on `GET /api/php`** — same values, same rules, so one component renders both screens. An installing Node version appears here with no `path` and no `npm_version`, for the same reason: nothing is on disk yet. `reason: "package_not_found"` on Node means fnm doesn't know that version.
- **`system`** is a Node that was already on the box; reported so it can be used, never modified. `manager: "none"` means no Node at all — both are normal states, render the install prompt.

**`PUT /api/node/default`** (`manage`) — `{"default": "20.11.0"}`. Moves `/usr/local/bin` symlinks only. **A site that pinned a version is unaffected** — it holds an absolute path in its unit. `422` if not installed.

**`POST /api/node/versions`** (`manage`) — `{"version": "20.11.0"}` → **`202`**, queued. Already installed returns `200`. Two clicks collapse into one job.

**`DELETE /api/node/versions/{version}`** (`manage`) → `204`. `422` when a site pins it (**every** site named) or when it's the default.

**`POST /api/node/versions/{version}/npm`** (`manage`) — updates npm inside that version using that version's own npm. Returns `{message, npm_version}` so the row can update without a refetch.

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
  "network":{ "in": 10240, "out": 5120, "in_human": "10 KB/s", "out_human": "5 KB/s" },  // bytes/sec
  "disk_io": { "read": 2097152, "write": 524288,                                          // bytes/sec
               "read_human": "2 MB/s", "write_human": "512 KB/s",
               "read_ops": 45, "write_ops": 12 }                                          // IOPS
} }
```

- **Polling.** This endpoint returns in ~10 ms and never blocks. `cpu.percent`, `network` and `disk_io` are rates **measured against your previous poll**, so poll it on a timer (2–5 s) and the numbers describe the interval you just watched. **The first poll after a gap returns `0` for those fields** — there is nothing to measure against yet. Show `—` or a flat line for one tick; do not treat it as an idle server.
- `disk_io` — disk throughput **and IOPS**, both as a rate. Throughput answers "is the disk saturated", `read_ops`/`write_ops` answer "is it thrashing" — on a database server the second is usually the real question, and a disk can be at 100% busy while moving very few megabytes.
  - Counted over **whole physical disks only**. `/proc/diskstats` lists partitions and loop devices alongside their parent disk, so a naive sum reports the same traffic two or three times.
  - Like `network`, this is a **rate between two reads**, not a running total — a quiet disk reads `0`, and the number never grows just because the server has been up longer.

**`GET /api/server/metrics/history`** — 24h series for the **CPU / Memory / Disk / Load / Network / Disk I/O** charts. `{ metrics: [{sampled_at, cpu, memory, swap, disk, load_1, load_5, load_15, net_in, net_out, disk_read, disk_write}, …] }` (5-min cadence). `disk_read`/`disk_write` are bytes/second, same units as the network pair; IOPS is live-only and not stored.

**`GET /api/server/processes`** — server process table (top by CPU). `{ processes: [{pid, user, cpu, memory, command}, …] }`.

**`DELETE /api/server/processes/{pid}`** (`dashboard` **manage**) — stop a process.
- Body: optional `signal`, one of **`TERM`** (default) or `KILL`. TERM asks the process to shut down and lets it flush and close files; KILL gives it no chance to, which is why it is not the default. Offer it as a second click ("Force stop"), not the first.
- Response `200`: `{"process": {pid, command, user, signal}}` — read at kill time, so it reflects what was actually stopped.
- **`404`** — the PID is no longer running. Refresh the table; do **not** show this as success. PIDs are recycled, so a stale row may now point at a different process entirely.
- **`422`** — refused. PID 1, kernel threads, the panel's own PHP, and processes belonging to protected services (nginx, php-fpm) cannot be stopped here. Show the returned `message`; these are permanent refusals, not retryable.
- **`500 {message, reference}`** — the signal failed (usually permissions).
- Logged to the activity trail as `server.process_killed` with the pid, command and signal.
- Confirm before calling. Stopping the wrong process can take a site or a database down, and there is no undo.

*(Deferred — Phase 3, with the Databases feature: `GET /api/server/database/metrics/history` (query/QPS chart) + `GET /api/server/database/processes` (DB process table + kill-query), engine-detected.)*

### Databases (P1)
Requires the `database` permission (`view` to read, `manage` to mutate). **3 engines** — `mysql | mariadb | mongodb` — via a `DatabaseEngine` strategy (SqlEngine covers mysql+mariadb, MongoEngine its own). Every op runs locally through the engine client with the admin creds in a 0600 auth file + statements over stdin (never a password on argv). A **DB user belongs to exactly one database** (nested resource). Identifiers are strict-regex validated (DDL can't be parameterised). Passwords are encrypted at rest but returned so you can build the connection string. `500 {message, reference}` on an engine failure.

**`GET /api/databases/engines`** — capability list: `{ engines: [{engine, driver, running, version, installed, charsets, installable, install_status, install_reason, install_message}] }`.

- **`running`** = answered a live `SELECT VERSION()` just now. **`version`** is that answer, so the two can never disagree — **`running: false` with a non-null `version` cannot occur.**
- **`installed`** = present on the server, whether or not it is up. This is the field that separates the two states you actually need different screens for:

| `installed` | `running` | Means | Tell the user |
|---|---|---|---|
| `false` | `false` | not on the server | install it |
| `true` | `false` | present but not answering | **start the service**, or check the connection settings |
| `true` | `true` | working | — |

- Detected from the package manager (`dpkg-query`) when the engine has an installer; MongoDB has none, so it falls back to the client binary — weaker evidence, since a client can exist without a server.
- **`installable`** is a different question again: whether the *panel* can install it for you. MongoDB is `installable: false` because it needs its own apt repository.

- **`installable`** — whether the panel can put this engine on the server itself. `true` for `mariadb` and `mysql`; **`false` for `mongodb`**, which is operable but needs its own apt repository, so don't render an install button for it.
- **`install_status`** is only ever `installing`, `failed`, or `null` — never `installed`. A finished install **deletes its progress row** so that detection (`running` / `version`) stays the single answer to "is it there", and the two can't drift. `null` + `running: false` means "not installed, nothing in flight".
- **`install_message`** is the localized sentence for `install_reason`, built in *your* `Accept-Language`. Reasons: `package_not_found`, `apt_lock`, `no_space`, `network`, `dpkg_broken`, `port_in_use_by_mysql`, `port_in_use_by_mariadb`, `root_unreachable`, `grant_failed`, `unknown`.

**`POST /api/databases/engines/{engine}`** (`manage`) — install it. `202 { queued: true }`; poll the endpoint above and drive the UI from `install_status`.
- Already installed → `200 { queued: false }` with the capability list. Not an error: a migrated server that already had MariaDB is a success, not a conflict.
- Not installable (`mongodb`) → `422`.
- **Only one SQL engine per server.** MySQL and MariaDB are mutually exclusive on 3306 — installing one while the other is present is refused with `port_in_use_by_*`, because on Debian-family systems apt would *remove* the first as a conflicting package and take its databases' server with it.

**What it does with credentials, and what it deliberately doesn't.** The panel creates **its own account** — `panel_` plus ten random characters — with `ALL PRIVILEGES … WITH GRANT OPTION`, and stores that password encrypted. It **never sets a root password**: on MariaDB 10.4+ and MySQL 8 on Ubuntu, root authenticates over the unix socket and has password login disabled outright, so giving it one would be *creating* a secret rather than reading one — and would make root usable over TCP. `sudo mysql` keeps working for the server's owner exactly as before.

If the panel **can't** sign in as root over the socket — someone already changed how root authenticates — it refuses with `root_unreachable` rather than guessing, because overwriting an existing root credential could lock out whatever else on the box depends on it.

The panel's own account is also protected from deletion through **Database Users**: it looks like an ordinary user there, and removing it would break every database operation with no way back through the UI.

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
- **`POST /api/databases/{database}/export`** (**queued**) — dumps the DB (`mysqldump --single-transaction` / `mongodump --archive --gzip`) to a managed exports dir. Source DB untouched; activity `database.exported` written **on completion**, not on request.
  - **`202 { export: {...} }`** — the work is queued. The response body is the row at `status: "queued"`, with `file` and `download_url` **null**. Poll and drive the UI from `status`.
  - **It used to be `201` and run inline.** A dump of any real database outlives nginx's `fastcgi_read_timeout` (300s) while `mysqldump` runs to 600s — so the browser was shown a failure while the dump carried on and succeeded, leaving a file nobody could find.
  - Export shape: `{id, database_id, database, engine, file, status, size_bytes, size_human, reason, message, reference, available, download_url, requested_by, created_at, created_at_human, finished_at, finished_at_human}`.
  - **`status`**: `queued` → `running` → `completed` | `failed`. Same polling shape as the engine installer.
  - On failure: `reason` is a stable code (`dump_failed`, `database_missing`, `worker`), `message` is that code worded in the **viewer's** locale, `reference` correlates with the server-ops log.
  - **`available`** is `false` when the file has since been deleted from disk by hand — `download_url` is null then too, rather than offering a link that 404s.
**Database size (`size_bytes` / `size_human`)** — served from a stored column, not measured per request, so the list stays fast with many databases.
- **`GET /api/databases`** returns the stored value. Refreshed by `databases:refresh-sizes` on a **10-minute** schedule.
- **`GET /api/databases/{id}`** re-measures before responding — one database, someone looking straight at it, worth the query.
- **It used to be written once at creation and never again**, so a database that had grown still reported roughly zero. It was never a performance problem; it was simply wrong.
- A probe that fails leaves the last known value rather than writing `0` — reporting every database on a stopped engine as empty is the same confident-wrong answer.

- **`GET /api/databases/exports`** (`view`) — every export, newest first. `{ exports: [ …same shape as above… ] }`.
  - **In-flight rows are included**, not filtered out — a `queued`/`running` export is exactly what someone who just pressed the button is looking for.
  - Rows survive their database being deleted (`database` is a copied name, `database_id` goes null), so a dump of something since-dropped is still listed and downloadable.
- **`GET /api/databases/exports/{file}`** (`view`) — streams a previously-created export for download. Filename strict-validated + resolved inside the exports dir (no traversal).
- **`DELETE /api/databases/exports/{id}`** (**`manage`**) — deletes the row *and* the file. `204`. Activity `database.export_deleted`.
  - **Keyed by id, not filename** (the frontend asked for `{file}`): a `queued` or `failed` export has no file, and by filename those rows would sit in the list permanently with nothing able to clear them.
  - `manage` rather than `view` because this destroys the only copy of that data; listing stays on `view`.
  - ⚠️ **Not yet automatic** — nothing prunes old exports on a schedule, so they still accumulate until someone deletes them. Retention is a separate piece of work.

*(Remaining P2: **import/restore** — deferred (writes data → will ship with existing-target-only + backup-before + confirm). P3: engine install-on-demand, app auto-DB + env-wiring, rename-database, phpMyAdmin signon SSO.)*

### Setup page / Services

**`GET /api/setup`** (`setting`) — one read that drives both the first-run wizard and the panel's Services page. They are the same list deliberately: building them separately would guarantee they drift, and something skipped on day one would then be lost rather than one click away.

```json
{ "setup": {
  "complete": false, "status": "installing", "percent": 60,
  "key": "database", "label": "Installing Database",
  "stack": "lemp", "web_server": "nginx",
  "components": [
    { "key": "database", "title": "Database", "description": "…",
      "state": "installing", "detail": null, "recommended": true,
      "action": null, "reason": null, "message": null, "retryable": false,
      "options": [
        { "value": "mariadb", "label": "MariaDB", "installed": false, "version": null,
          "installable": true, "recommended": true,
          "action": { "method": "POST", "endpoint": "/api/databases/engines/mariadb" } }
      ] },
    { "key": "php", "state": "installed", "detail": "8.4",
      "action": { "method": "POST", "endpoint": "/api/php/versions" }, "options": [] }
  ] } }
```

- **`state` is always detected, never remembered** — `installed · pending · installing · failed`. A server that already ran MariaDB shows it installed before anything is clicked; something removed later goes back to `pending`. So **`pending` means "we looked and it is not there"**, not "we have not tried". `installed` beats a stale `installing` row, so a spinner can never hang forever.
- **`percent` is derived** (`installed ÷ total`). It cannot go backwards or drift when a component is added.
- **`action` is the endpoint to call** — the ones that already existed for PHP versions, Node versions, fail2ban and database engines. There is no `POST /setup/...`: a second way to trigger the same install is the one that drifts. **`null` means the panel cannot install it** (Redis, MongoDB) — render no button rather than one that fails.
- **`options` is a pick-one** — only the database has them. MariaDB comes back `recommended: true`; MongoDB `installable: false`.
- **`complete` tracks the *recommended* set, not everything.** Nothing here is required: the installer already put the web server, PHP and Node in place, so the panel works from first boot. A wizard that demanded the rest would block people over preferences.
- **A failure keeps its `reason`, a localized `message` and `retryable: true`** — never cleared. There is nowhere to go back to when the panel *is* the server.
- The web server is **not** on this list. It is chosen when the installer runs (`--stack=lemp|lamp|mern`) and serves the panel itself, so it cannot be swapped from inside the panel without the panel going down with it.

### Applications (Phase 1 — catalog + record only)

Requires the `application` permission (`view` to read, `manage` to mutate).

> **Phase 2 provisions the site.** Creating an application queues the work: directory → ownership → placeholder page → site config → **config test** → reload. The response returns before any of that has run, so **poll `GET /applications/{id}` and drive the UI from `status`**. Code is still not fetched — a new site serves a placeholder page until git deploy (P3) lands.

One-click types available today: **WordPress**, **Nextcloud**, **Joomla**, **Moodle**, **Mautic**, **Craft CMS**, **Akaunting**, **Statamic**, **PrestaShop**, **phpMyAdmin**.

**Two of these need no database** — phpMyAdmin (it reads the ones already there) and **Statamic** (flat-file). `needs_database` on the card says which, so the create form can leave the database step out.

**Installers keep secrets off the command line wherever the application allows it** — they go into a config file the application reads, or into a prompt answered on stdin. **Two cannot: Statamic and PrestaShop.** Their installers take the password as an argument and offer no alternative (PrestaShop's reads `$argv` and never touches stdin; Statamic's only prompt path throws when input is piped). For the seconds those commands run, the password is visible to a local user reading `ps`. Recorded here so it is a known exception rather than a surprise.

**`web_root` defaults per type, not to `/`.** Craft CMS serves from `/web`; serving its root would publish the application's source and its `.env`. The `web_root` field carries the right default for the chosen card — send it back unchanged unless the user edits it, and if you omit it entirely the API applies the type's default rather than the site root. **Nextcloud** takes an admin user, email and password, and gets its own database. Its archive is ~280 MB, so provisioning takes noticeably longer than the others — the `steps[]` progress on `GET /api/applications/{id}` is the thing to show. phpMyAdmin takes no fields beyond the common ones and creates **no database** — it reads the ones already on the server, and each user signs in with their own database credentials.

**`GET /api/site-types`** — the card grid. One entry per installable thing, each carrying its own field schema, so the frontend writes **one** generic form renderer and a new app type needs no frontend change.
- Response: `{ site_types: [{name, title, tagline, icon, category, popular, method, serving_profile, needs_database, available, unavailable_reason, installable_runtime, has_installer, fields[]}] }`
- `method` (`one_click|git|custom`) is internal — **do not ask the user to choose a method.** Render one grid of real things; "From Git repo" and "Blank PHP site" are simply cards in it.
- `available: false` means this server can't run that type. **Grey the card and show `unavailable_reason` — don't hide it**, otherwise the user never learns the option exists. **`installable_runtime`** names the runtime that would fix it (`php`/`node`), so an unavailable card can offer to fix itself; `null` when the card is available.
- There are now **two reasons** a card can be unavailable, and they need different UI:
  - **Missing runtime** — `installable_runtime` is `php` or `node`. Offer to install it; the card becomes usable afterwards.
  - **Not supported on this web server** — `installable_runtime` is **`null`**. Nothing the user can install will fix it, so offer no action. **Switch on `installable_runtime`, not on the message.**
- **`serving_profile` follows `rendering_type`, and the start command where there is no rendering type.** An application served **`node`** gets a reverse proxy to its port on all three web servers, WebSocket upgrades included, because an app that routes in code must not have its directory served instead. The field is derived; never send it.
- **`rendering_type` — how a git repository is served. Asked outright, never guessed.** It is `required` on the git card and deliberately has **no default**: a Node app served as a directory publishes its source, and a PHP app served by proxy is a permanent 502, so a wrong guess is invisible until the site is live. Four answers, and the resulting profile:

  | `rendering_type` | means | `serving_profile` | process? |
  |---|---|---|---|
  | `php` | Laravel, Symfony, plain PHP | `php` | no |
  | `ssr` | Next.js, Nuxt, Express, Nest | `node` | **yes** |
  | `csr` | React/Vue/Angular SPA, built to files | `static` | no |
  | `static` | Astro, Hugo, plain HTML | `static` | no |

  - `start_command` and `app_port` carry `depends_on: "rendering_type"` — **show them only for `ssr`.** For `ssr`, `start_command` is required (`422` without it). For anything else both are dropped from the request rather than stored, so a leftover value in your form can't create a unit nothing routes to.
  - It is editable on `PUT`. **Changing away from `ssr` clears `start_command` and `app_port`** — the process is gone, so keeping either would render start/stop controls with no unit behind them and hold a port the next application could have had. Re-read the application after the update rather than assuming your form still matches.
- **All 13 types are available on every web server, OpenLiteSpeed included.** An audit found nothing in any installer or site type that depends on the web server — none mention `.htaccess`, `mod_rewrite` or Apache, none declare extension requirements, and the site-type contract has no web-server concept. The per-web-server restriction exists in config if a real limitation ever turns up, but none is claimed today. (OLS is still unproven on real hardware — that's a verification caveat, not a narrower catalog.)
- The create endpoint applies **the same check**, so a card you can click can never be refused for a reason the grid didn't show. A blocked type returns `422` with the reason on `site_type`.
- **Four one-click Node applications** — `uptimekuma`, `n8n`, `nodered`, `nodebb`. They behave like the PHP marketplace with three differences the form has to respect:
  - **Never ask for a start command.** The installer writes it; there is one right answer per application and the field is not in their schema. `app_port` is present but **advanced** — the panel allocates a free one.
  - **They need Node**, so the card is greyed with `installable_runtime: "node"` on a server without it, exactly like a PHP card without PHP.
  - **`nodebb` needs MongoDB and cannot use MySQL.** On a server without MongoDB the card is greyed with `unavailable_reason` naming it and **`installable_runtime: null`** — the runtime installer cannot fix a missing database engine. It is the only card blocked this way.

  | Type | Admin account | Data |
  |---|---|---|
  | `uptimekuma` | **created by the first visitor** — there is no setup CLI | SQLite in the site |
  | `n8n` | **created by the first visitor** | SQLite in the site |
  | `nodered` | `admin_username` + `admin_password`, both **required** | files in the site |
  | `nodebb` | `admin_username` + `admin_email` + `admin_password` | MongoDB |

  For the two with no admin fields, **tell the user to open the site and claim it** as soon as it goes active. Until they do, anyone who reaches the URL can. `n8n` is fair-code (Sustainable Use Licence), not open source — its tagline says so and the UI should not hide that.
- **`has_installer`** — whether picking this type actually installs software. `true` for WordPress and phpMyAdmin (you get a working application); `false` for git / blank PHP / static (you get a served directory and supply the contents yourself). Use it to word the card and the confirm step — the two outcomes are very different and the card is otherwise identical.
  - *(This field was previously called `installable`, which held the runtime and read as though it meant "installs itself". It now says what it holds.)*
- Each **field**: `{name, label, type, required, advanced}` plus optionally `default`, `help`, `options`, `generate`, and the two keys that drive dependent dropdowns:
  - **`source`** — which endpoint fills it: `git_accounts`, `git_repositories`, `git_branches`, `system_users`, `php_versions`, `node_versions` (the last from `GET /api/node`, same shape as `php_versions`)
  - **`depends_on`** — don't load until that field is chosen; clear this field when the parent changes
- `advanced: true` fields belong behind an **Advanced** toggle, collapsed by default.

**`GET /api/applications`** → `{ applications: [ …application objects… ] }`
**`GET /api/applications/{application}`** → `{ application: {…} }`

**`POST /api/applications`** (`manage`) — create the record **and queue provisioning**. `201 { application: {…} }` returns immediately at `status: "pending"`; poll until `active` or `failed`.
- Always: `site_type`, `name`, `domain`, `system_user_id`.
- Then whatever the chosen type's schema declared. **Validation is generated from that same schema**, so WordPress rejects a missing `admin_email`, and keys the type never declared are ignored rather than stored.
- `serving_profile` is derived from the type — not accepted from the client.
- **Git — two paths, exactly one required:** `git_source: "account"` needs `git_account_id` + `repository`; `git_source: "public_url"` needs `repository_url` and **no account at all** (a public repo needs no credentials). Pasted URLs must be `https://` and may not point at loopback or the cloud metadata range.
- `422` on a site type this server can't run — the record would describe something unprovisionable.

**`PUT /api/applications/{application}`** (`manage`) — `name`, `domain`, `web_root`, `build_command`, `rendering_type`, `start_command`, `app_port`, `branch`, `settings`. `settings` **merges**, so a partial update doesn't wipe the other answers. The site type is not editable — a different type is a different application.

**`POST /api/applications/{application}/provision`** (`manage`) — retry after a failure. `202 { application: {…} }` with `status: "provisioning"`; the previous `failed_step`/`reference` are cleared. Provisioning is **never retried automatically** — repeating a server change the user hasn't seen the reason for is how half-applied state happens.

**`POST /api/applications/{application}/deploy`** (`manage`) — fetch the code from git. `202 { application: {…} }`; poll as with provisioning. **Git applications only** — `422` for anything else, since there is nothing to pull.
- First deploy clones; later deploys `fetch` + `reset --hard` to the branch, so the working tree matches the branch exactly. Local edits on a deploy target are not preserved — keeping them would silently break the next deploy instead.
- `build_command` (if set) runs after the code is fetched, **as the site's own system user** — never as the panel.
- On success: `last_commit` + `last_deployed_at` record what is actually on disk.
- **A failed redeploy leaves a live site live.** The old code is still there and still served, so `status` stays `active` and only `failed_step`/`reference` are set. Show that as a deploy warning, not as an outage.
- **A burst of pushes queues one deploy, not one per push.** The job is unique-until-processing per application: at most one running plus one waiting. A push that arrives mid-deploy still queues the follow-up, because the running deploy fetched the tip before that commit existed.

#### Deploy on push (webhooks)

**`GET /api/webhook-providers`** (`application`) → `{ webhook_providers: [ { name, title, secret_source, instructions } ] }`. Render the setup steps from this rather than hardcoding three sets — **`secret_source` differs and it matters**:

| Provider | Verified by | `secret_source` |
|---|---|---|
| `github` | HMAC-SHA256 over the raw body, `X-Hub-Signature-256` | `generate` — we mint it, the user pastes it into GitHub |
| `bitbucket` | HMAC-SHA256 over the raw body, `X-Hub-Signature` | `generate` |
| `gitlab` | its **signing token** (HMAC over `id.timestamp.body`, Standard Webhooks) *or* its legacy plaintext `X-Gitlab-Token` | `either` — GitLab mints the signing token and shows it once, so that one can only be **pasted in**; the legacy one we can generate |

**`PUT /api/applications/{application}/webhook`** (`manage`) — body `{ enabled, provider?, secret?, rotate? }` → `{ application: {…} }`. Git applications only (`422` otherwise).
- `provider` is **required when enabling**; it is stored, not detected from the incoming request, so a caller cannot pick which verification runs. For a public repository there is no connected account to infer it from, which is why it is asked for.
- `secret` omitted → the panel generates one (64 hex chars). Paste one instead for a **GitLab signing token**.
- `rotate: true` mints a new secret and invalidates the old, keeping the same URL.
- Disabling **keeps the URL and the secret**, so switching it back on does not invalidate what the user already pasted into their repository settings.

The application resource carries a `webhook` block:

```json
"webhook": {
  "enabled": true,
  "provider": "gitlab",
  "url": "https://panel.example.com/api/webhooks/deploy/6f1e…",
  "secret": "…",
  "verification": "token",
  "last_delivered_at": "31-07-2026 11:40:02",
  "last_delivered_at_human": "2 minutes ago"
}
```

**`verification` is worth surfacing.** `signature` means the delivery is HMAC-verified; `token` means a plaintext shared secret, which only happens on GitLab when no signing token was pasted. Offer the user the upgrade rather than leaving them on the scheme GitLab itself labels not recommended.

**`POST /api/webhooks/deploy/{identifier}`** — **the provider calls this; your app never does.** Unauthenticated by design: the signature over the body is the credential, and no token exists on a request from GitHub. Not rate-limited per IP (a provider delivers from shared egress) but per webhook, 60/min.

| Response | Meaning |
|---|---|
| `202 { deployed: true, reason: "queued" }` | authentic push to the tracked branch — deploy queued |
| `202 { deployed: false, reason: "other_branch" }` | authentic, but not this application's branch (also: a tag, or a branch deletion) |
| `202 { deployed: false, reason: "not_a_push" }` | authentic, but some other event type |
| `202 { deployed: false, reason: "duplicate_delivery" }` | the provider retried a delivery already handled |
| `401 { deployed: false, reason: "invalid_signature" }` | not authentic |
| `404` | no such webhook, **or** it is disabled — deliberately indistinguishable |

**Everything authentic answers 2xx, including the deliveries that deploy nothing.** Providers disable a hook that keeps failing (GitLab after four consecutive failures), so "understood, nothing to do" must not look like a fault.

Two provider behaviours worth telling users about: GitLab sends **no webhook at all** for a push touching more than 3 branches or tags, and both GitLab and GitHub time out around 10s — which this endpoint is well inside, since it only queues.

**`DELETE /api/applications/{application}`** (`manage`) → `{ deleted: true }`. Also **removes the site config and reloads**, so the domain stops being served. The site's **files are kept** unless you pass `?remove_files=true` — deleting a panel record must not silently destroy someone's code. An application still at `pending` touches nothing on the server.

#### Provisioning status

| `status` | Means | UI |
|---|---|---|
| `pending` | queued, nothing done yet | "Not deployed yet" |
| `provisioning` | running | show `steps[]` as progress |
| `active` | serving | the domain loads |
| `failed` | stopped at `failed_step` | show the step + a **Retry** button |

- `steps` — the steps completed in order, **written as each one finishes**, so polling this while `status` is `provisioning` shows real progress. Provisioning: `create_directory`, `set_ownership`, `placeholder`, `write_config`, `test_config`, `reload`. **One-click apps then continue:** `create_database` (only when the app needs a database), `download`, `extract`, `configure`, `install_cli` (only when the setup tool is missing), `install_app`, plus a few app-specific ones (`harden`, `trust_domain`, `set_password`, `install_cache`). **Apps that run a process end with** `start_app`, or `write_unit` for a git app whose code has not arrived yet. Deploy: `clone` (or `fetch` + `checkout`), `set_ownership`, `build`, `restart_app`. Localized labels are in the `application.steps.*` translations.
  - Do not treat the list as fixed: a step only appears if it ran, the exact set depends on the site type, and **a new step can be added by a future release**. Render the ones you know and skip the ones you don't — the last entry is where it currently is.
  - **A failure keeps what it got through.** `steps` is what completed, `failed_step` is what broke; a retry starts the list again from empty.
- `failed_step` — which one broke (`worker` means the background process itself died — the job was killed or the worker was, rather than a step returning an error).
- `reference` — quote this to support. **The raw server error is deliberately not in the response**; it's in the server-ops log under this id.

**The config is always tested before any reload, and a failed test removes the config we just wrote.** A broken vhost that reached a reload would take every other site on the server down — so one site's bad config can never cost the whole box.

A server running a web server the panel can't configure (currently anything other than nginx or apache) is **refused with a 422** rather than guessed at.

#### One-click applications

A site type with `needs_database: true` (currently **WordPress**) is installed automatically once its site is serving:

1. **A database and a dedicated user are created** — named from the domain, with a generated password. Linked to the app via `databases.application_id`, so `GET /api/databases` shows it.
2. The application is **downloaded over https**, unpacked in a temp directory, then moved into the web root.
3. Its config file is written **`0640`, owned by the site user** — it holds live database credentials.
4. The application's own setup runs **as the site user**, never as the panel.

- The **admin password the user typed is never a command-line argument** — it goes in over stdin. Anything on a command line is visible in `ps` to every user on the machine.
- **Each install gets its own security keys.** If the upstream key service is unreachable, locally generated randomness is used — never a shared fallback set.
- If **no database engine is available**, the install fails at `create_database` **before anything is downloaded**, with `errors/application.no_database_engine`. Nothing is left half-written.
- **Deleting the application does not drop its database.** That has to be done from the Databases screen — losing data as a side effect of removing a record isn't acceptable.

Site types with no installer (`git`, `php`, `static`) skip all of this; there is nothing to install for a site whose contents the user supplies.

#### The application object
```json
{
  "id": 4, "name": "My shop", "domain": "shop.example.com",
  "site_type": "git", "site_type_title": "From Git repo",
  "serving_profile": "php", "rendering_type": "php",
  "status": "pending", "status_title": "Not deployed yet", "deployed": false,
  "system_user": { "id": 3, "username": "deploy" },
  "php_version": "8.4", "node_version": null, "app_port": null,
  "web_root": "/", "build_command": null, "start_command": null,
  "git_account_id": 1, "repository": "octocat/hello",
  "repository_url": null, "branch": "main",
  "settings": {},
  "created_at": "29-07-2026 08:10:00", "created_at_human": "2 minutes ago"
}
```
- `deployed` is the honest flag — true only when `status` is `active`. In P1 it is always `false`.
- `git_account_id: null` with a `repository_url` = a public repository, cloned without credentials.
- `settings` holds the type-specific answers (WordPress admin email, table prefix, …), shaped by that type's field schema.

**`GET /api/applications/port-check?port=8080[&application_id=3]`** (`view`) — ask before submitting, so the user is warned as they type rather than refused after.
```json
{"port_check": {"port": 8080, "available": true, "reason": "registered",
                "service": "http-alt", "suggested_port": null,
                "message": "Port 8080 is normally used by http-alt. You can still use it if nothing on this server does."}}
```
- **Three outcomes, not two.** `available: true, reason: null` → fine. `available: true, reason: "registered"` → **a warning, not an error**: `/etc/services` has a name for it (`service`), but the user may well mean it. `available: false` → taken, with `reason` either `in_use_by_app` (another application here) or `in_use` (something outside the panel is listening) — those send the user to different places, so show the message rather than a generic one.
- **`suggested_port`** is only present when the answer is no. Offering an alternative to someone whose choice was fine is noise.
- Pass **`application_id`** when editing, so an application is not told its own current port is taken.

**`POST /api/applications/{id}/process/{start|stop|restart}`** (`manage`) — control the application's own process.
- `200 {application}` with fresh live status · `422` if the application runs no process · `404` for any action outside those three · `500 {message, reference}` when systemd refuses.
- **`has_process`** on the resource is the flag to render controls from — it is true exactly when `start_command` is set. PHP and static sites are false; show no start/stop buttons for them.
- **`process`** is present only when `has_process`, and is read **live from systemd on every request** — never stored, so it cannot drift from reality: `{state, sub_state, since, memory, restarts}`. `state` is systemd's own vocabulary (`active`, `failed`, `activating`, …).
- **`app_port`** — send any port from 1024–65535 and the panel will use it. **The range in `server.applications.port_range` (3000–3999) is only what auto-allocation picks from when you send nothing**; it is not a restriction on what you may choose. This is the user's own server.
  - A port you choose is refused only for a **real** conflict, with a message naming which: `port_in_use_by_app` (another application here has it) or `port_in_use` (something outside the panel is listening on it). Both come back as a `422` on `app_port`.
  - A port merely *named* in `/etc/services` is **allowed** — 8080 is `http-alt` there and is also where most Node apps listen. Auto-allocation avoids those names; your explicit choice is not second-guessed.
  - Auto-allocation skips `/etc/services` names so a site never lands on 3306 and collides with a MySQL installed later, and the range sits below the kernel's ephemeral range so the OS cannot hand the same port to an outgoing connection.
- **`start_command` is executed directly, not through a shell.** Two things are refused with an explanatory message: shell syntax (`&&`, `|`, `;`, `$(`, redirects), and starting via `npm`/`yarn`/`pnpm`/`bun`/`npx`. Use the entry file — `node server.js`. A package manager forks the real process, so shutdown signals never reach the app and it is killed by timeout instead.
- **When the process starts depends on whether the code is there yet**, and the step name tells you which happened:
  - **One-click Node app** — installed, then started. Step `start_app`.
  - **Git app (`rendering_type: "ssr"`)** — provisioning writes and enables the unit but does **not** start it, because the repository hasn't been cloned yet. Step `write_unit`. It starts on the first successful deploy, step `restart_app`. So a freshly-created git app is `active` with `has_process: true` and a process that is not running — that is correct, not a fault. Show "deploy to start", not an error.
- It is stopped, disabled and removed when the application is deleted.

*(Web server is **not** an application field — it belongs to the server, which owns port 80. Nor is the database engine: it follows from the app type. See `GET /api/server/capabilities` below.)*

**`GET /api/server/capabilities`** — what this server is and can run. Written by the installation script; if the row is missing (a server migrated in from elsewhere) it is detected once and stored on first use.
- Response: `{ capabilities: {stack, web_server, capabilities: {php, node}, source, verified_at} }`
- `stack` (`lemp|lamp|ols|mern`) is how the box was **built**; `capabilities` is what it can run **now**. They legitimately differ — installing Node on a LEMP box adds the capability without changing how it was built — so **filter the UI on `capabilities`, never on `stack`.**
- `web_server` is `nginx|apache|openlitespeed`. **`mern` is not a web server** — a MERN box runs nginx.
- **All three can provision applications** as of 2026-07-31; OpenLiteSpeed previously refused every site type. A web server outside that list is still refused rather than guessed at — provisioning fails immediately, before anything is written to disk.
- **`openlitespeed` changes two things for the UI**, both because OLS runs LSPHP rather than PHP-FPM:
  - **PHP extension toggles return `422`** — there is no `phpenmod` equivalent. Hide them.
  - **PHP contributes no rows to the Services screen.** LSPHP is spawned by the web server, so there is no `php8.4-fpm` unit to start or stop; the `service` field on a PHP version is `null`. The OpenLiteSpeed service itself (`lshttpd`) is listed as normal.
- ⚠️ **OpenLiteSpeed support has not yet run on a real OLS server.** The logic is tested; the paths and directives come from LiteSpeed's documentation. Expect the first live box to need corrections in `config/server.php`.

### The application sidebar (`GET /api/permissions?level=application&application_id=…`)

**The sidebar for one site is filtered by the backend. The frontend renders what it gets and writes no conditions.**

Two filters decide whether an item appears, and both are applied server-side:

1. **What the user was granted** — `view` / `manage`, from their roles.
2. **What the site type can actually do** — a WordPress install has no git repository, a static site has no PHP.

| request | answer | use it for |
|---|---|---|
| `?level=application&application_id=7` | that site's sidebar, both filters applied | **the app sidebar** |
| `?level=application` | all 16 items, grants only | **the role form** — an admin assigning a role is not looking at one site |
| `?level=server` | unchanged | the server sidebar |

`application_id` on a `level=server` request is ignored: a server permission has nothing to do with any one site. An id that does not exist is a `422`.

**Hide, don't grey.** There is nothing a user can do to enable PHP settings on a static site, so a disabled row is only noise. Greying is for things they *can* fix — like a site-type card that names the runtime to install.

What each type supports is declared by the type itself, so a new site type costs one class and no frontend change — the same trade `GET /site-types` already makes for the create form. Today:

- **Every site:** Dashboard · Domains & SSL · Logs · Backups · Settings · Files · Password Protection · Firewall · AI Bot Blocker · Fail2ban · Site Clone
- **Deployment** — git sites only. A one-click install has no repository, branch or commit history.
- **PHP Settings** — PHP sites only.
- **Workers** — Node sites and git sites. A marketplace PHP app has nothing to supervise.
- **Environment** — git and Node sites, plus **Craft CMS and Statamic** (both read a `.env` despite being one-click). **Not WordPress** — its configuration lives in `wp-config.php`, which is the application's file, not an env file the panel owns.
- **Staging** — WordPress only for now: pushing a staging site back needs URL rewriting inside serialised data, and that recipe exists for WordPress and nothing else yet.
- **phpMyAdmin** drops Backups and Site Clone — it holds no content of its own, so reinstalling is the honest recovery path. Password Protection stays, because an exposed phpMyAdmin is a login page for every database on the box.

#### Hiding is not authorising

Every app route gated by an `app_*` permission also checks the site type, so the endpoint is closed even if someone types the URL. It answers **`404`, not `403`** — for this site the screen does not exist at all, which is a different statement from "you may not".

⚠️ **`POST /api/applications/{id}/deploy` has moved from `application,manage` to `app_deployment,manage`.** It is the Deployment screen's action, so it takes that screen's permission. Two consequences: a role with server `application` but not `app_deployment` can no longer deploy, and deploying a non-git site now returns `404` instead of `422` — the refusal happens before the controller rather than as a validation failure inside it.

### Application domains (App sidebar → Domains)

Requires the **`app_domain`** permission (`view` to read, `manage` to mutate) — an
*application*-level permission, not the server-level `application`. The two are
deliberately separate: sharing one permission across that line would turn "can
manage this one site's domains" into "can manage every application".

A site is no longer one hostname. Every name it answers to is a row, and every
row has a **type** that says what that name does:

| type | what it does |
|---|---|
| `primary` | The canonical name. Exactly one per application. The vhost file and both log files are named after it. |
| `alias` | Serves the same content under a second name. |
| `redirect` | Serves nothing — sends a `301` (or `302`/`307`/`308`) to `redirect_to`. |

**The alias/redirect distinction is not cosmetic.** An alias makes search engines
index the same site twice and split the ranking between the two names; a redirect
keeps the authority on one. Say so in the UI — most users pick "alias" meaning
"redirect".

**`GET /api/applications/{id}/domains`** — primary first, then alphabetical.
- Response: `{ domains: [{id, domain, type, type_title, redirect_to, redirect_status, is_test, dns_verified, dns_verified_at, dns_verified_at_human, dns_resolved_ip, behind_proxy, certifiable, created_at, created_at_human}] }`

**`POST /api/applications/{id}/domains`** → `201 {domain: {...}}`
- Body: `domain` (required), `type` (`alias|redirect`, default `alias`), `redirect_to` (required when `type=redirect`), `redirect_status` (`301|302|307|308`, default `301`).
- **`primary` is not accepted here** — promoting a name is its own endpoint, because it renames three files.
- `domain` is unique **across every application on the server**, not just this one. Two sites claiming one hostname is otherwise resolved by whichever vhost the web server reads first.
- The charset is strict (lowercase hostname labels only). This value ends up in a filename and inside a config directive, so anything that could introduce a path separator or break out of the directive is refused here — a `422` on `domain`, not an escaped string later.
- Adding a domain **rewrites and reloads the vhost**. If the new config fails its test, **the previous one is put back** rather than removed — a mistyped hostname must not take a live site down.

**`POST /api/applications/{id}/domains/{domain}/verify`** → `{domain: {...}}`
- Re-checks DNS. Its own button because propagation is something the user waits on: they add a record at their registrar and come back.
- **`dns_verified: false` is the gate on offering a certificate.** Let's Encrypt allows five authorisation failures per hostname per hour, so guessing is expensive — check first, then offer.
- **`behind_proxy: true`** means the name resolves to Cloudflare, not to this server. DNS is correct *and* HTTP validation will still fail, because the proxy answers first. This is the single most common support question this feature will generate — surface it as its own message ("pause the proxy, or use DNS validation"), not as a generic failure.

**`POST /api/applications/{id}/domains/{domain}/primary`** → `{domains: [...]}`
- Promotes a name to canonical. The name it replaces stays attached as an alias, so the site keeps answering on it.
- This **renames the vhost and both log files** and removes the configuration under the old name.
- ⚠️ **It does not rewrite URLs stored inside the application.** A WordPress site keeps its old `siteurl` in the database and will redirect straight back. Warn before confirming.

**`DELETE /api/applications/{id}/domains/{domain}`** → `204`
- The **primary is refused** (`422` on `domain`): removing it would leave the site with no canonical name, no vhost filename and no log paths. Promote another name first.

- **`certifiable: false`** means this name can never go on a certificate. Test domains
  (`*.nip.io`) are the case: nip.io is not on the Public Suffix List, so every
  certificate issued for it *anywhere on the internet* shares one weekly limit.
  Hide the SSL action rather than letting it fail.

### Certificates / SSL (App sidebar → Domains)

Same permission as domains — **`app_domain`** (`view` to read, `manage` to
mutate). Two permissions would let someone add a domain but not secure it,
which is not a state anybody wants to be in; Forge's own 2025 redesign merged
the two screens for the same reason.

**One certificate per application.** A server block presents exactly one, so a
second record would be a certificate serving nothing. Reissuing replaces the
row; what it replaced is in the activity log.

**`GET /api/applications/{id}/certificate`** → `{certificate: {...} | null}`
- **`null`, not `404`.** "This site has no certificate" is a normal state the screen has to render, not an error.
- Fields: `id, type, type_title, status, domains[], missing_domains[], force_https, auto_renew, renewable, issued_at, expires_at, expires_at_human, days_remaining, expired, expiring_soon, reason, message, reference`

**`POST /api/applications/{id}/certificate`**
- Body: `type` = `letsencrypt` | `self_signed` | `custom`. For `custom` also `certificate`, `private_key`, and optionally `chain` (all PEM).
- **`letsencrypt` and `self_signed` return `202`** with `status: "pending"` — the work is queued. **Poll `GET .../certificate` and drive the UI from `status`** (`pending → issuing → active | failed`). ACME involves a round trip back to this server and routinely outlasts the request.
- **`custom` returns `201`** and is already `active`. There is nothing to wait for; adding a spinner to two file writes would be theatre.
- A `custom` upload is checked before anything is written: the key must match the certificate (`422` on `private_key`) and both must be PEM. A mismatched pair is otherwise written happily, fails the config test, and takes the site down over a copy-paste.
- **`force` (bool, optional)** skips the reachability dry run described below. Exists for one real case — a server behind NAT that cannot reach its own public address — and should be offered only *after* a refusal, never as the default.

#### The dry run (why the button sometimes says no)

Before certbot is called, the panel performs the challenge itself: it writes a random token into the ACME directory and fetches it back over plain HTTP. Only an exact match is a pass.

This replaces the old "is DNS verified?" gate, and the difference matters. DNS pointing here says nothing about whether the token will be **served** — port 80 can be firewalled, Cloudflare can be answering, or the site's own rewrite rules can swallow `/.well-known/` and return a 404 page. Let's Encrypt reads that as an authorisation failure and allows only **five per hostname per hour**, so a gate that lets those through is barely a gate. The dry run costs nothing against any limit, because the request is ours.

**The user never has to click "Verify DNS" first** — the check refreshes DNS itself as its first step.

On refusal the response is a `422` with one message per domain under `errors.domain`, each naming a distinct fix:

| reason | what the user must do |
|---|---|
| `dns_missing` | add an A record |
| `dns_not_pointing` | it resolves to another address — the message names it |
| `behind_proxy` | pause Cloudflare's proxy (grey cloud) |
| `blocked_ip` | resolves to loopback or the metadata range; never certifiable |
| `unreachable` | nothing answered on port 80 — firewall, or web server down |
| `challenge_redirected` | the site redirects the challenge instead of answering it |
| `challenge_not_served` | it answered, but not with the token — rewrite rules |
| `precheck_failed` | the panel could not write its own test file (not the user's fault) |

**Partial issuance:** if some names pass and others do not, the certificate is issued **for the ones that pass** and returns `202`. Blocking the whole request because a `www` record has not propagated helps nobody — the site gets HTTPS now, and `missing_domains` says what is left. Only if *nothing* passes is the request refused.

The check also never fetches a third party: if the name resolves somewhere other than this server, that is answered from the DNS result without any request being made.

**`PUT /api/applications/{id}/certificate/force-https`** → `{certificate: {...}}`
- Body: `force_https` (bool).
- **Refused with `422` unless a certificate is active.** This is not a preference: redirecting to HTTPS with nothing listening on 443 does not degrade the site, it takes it off the internet for every visitor at once — including the one who just clicked the toggle.

**`DELETE /api/applications/{id}/certificate`** → `204`
- Removes the TLS directives, clears force-HTTPS in the same step, and tells certbot to stop renewing. A renewal left behind keeps running forever, keeps spending rate limit, and eventually emails the user about a site they deleted.

#### What the frontend needs to get right

- **`status: "failed"` carries a `reason` code and a localized `message`.** Show the message; the codes are `rate_limited`, `rate_limited_failures`, `unreachable`, `dns_not_pointing`, `challenge_not_served`, `certbot_missing`, `self_sign_failed`, `unknown`. Each says what to do, because "it failed" is the least useful sentence a panel can produce about a certificate.
  - **`rate_limited` must not offer a retry button.** Retrying is precisely what must not happen — the wait is a week. `rate_limited_failures` is an hour.
- **`missing_domains` is the quiet failure.** A name added after issuance is served by a certificate that does not mention it: the browser refuses it, the server logs nothing, and the panel is the only place that can say so. If it is non-empty, prompt to reissue.
- **`renewable: false`** (uploaded and self-signed) means nothing will renew it. Show the expiry as a deadline the user owns, not as a date that will take care of itself.
- **`expiring_soon`** is computed server-side against one threshold so the rule lives in one place — certificate lifetimes are shrinking, and that threshold will move.
- **`expires_at` is kept current after renewal.** certbot's timer replaces the file every ~60 days without telling the panel, so a daily command (`certificates:refresh-expiry`) re-reads it off disk. Without that the screen would count down from the issuance date and report "expired" on a site whose certificate renewed correctly weeks earlier. **This needs the Laravel scheduler tick to be running** — the same cron entry every other scheduled feature depends on.
- **`reason: "file_missing"`** means the certificate the vhost points at is no longer on disk. Found by that same daily pass. It is reported rather than repaired: reissuing on a schedule would spend rate limit on a problem nobody has looked at. Offer a reissue button.
- **Issuance needs `dns_verified: true` on the domain** (see the domains section). Requesting without it is refused with `422`, deliberately: Let's Encrypt allows five failed authorisations per hostname per hour, and guessing locks the user out of the fix for an hour.
- **`self_signed` is the exception to that rule** — an internal or staging hostname that could never be validated publicly is the only reason it exists. Every browser will warn about it; say so in the UI rather than letting the user discover it.

#### Automatic issuance on site creation

When a site finishes provisioning, the panel runs the same dry run once and — **only if it passes** — issues a certificate on its own. No button, no request from the frontend.

**A decline writes nothing.** No certificate row, no activity entry, `certificate: null`. This is deliberate and the frontend should rely on it: for a genuinely new domain the DNS record almost never points at the server yet, so most sites will decline. If that wrote a `failed` certificate, every new site would open on a red SSL error about something the user has not set up yet. The SSL screen simply shows its ordinary install button.

Where it does fire is the case where DNS was pointed in advance — a site migrated from another server, or a record set before the site was created. For those, HTTPS is already there when the user first opens the site.

- **Never for test domains** (`*.nip.io`): every certificate issued for nip.io anywhere shares one weekly limit, and spending it automatically on every site created on every install of this panel would be antisocial.
- **Never over an existing certificate.** Provisioning can be re-run; reissuing over a working one spends rate limit to achieve nothing.
- **Cannot fail the provision.** By the time it runs the site is created, serving and correct — a DNS timeout must not turn that into a failed application.
- Operators can turn it off with `SV_AUTO_ISSUE_CERTIFICATES=false` (a box with no public DNS).
- **There is no background retry.** If it declines, nothing tries again on its own; the user installs from the panel when they are ready, and the button now says precisely why if it is still not possible.

#### How this works on the server, and why

- **certbot runs in `certonly --webroot`, never the `--nginx` / `--apache` plugins.** The plugins work by editing the vhost — the file this panel regenerates on every domain change. Their edits would be silently wiped and HTTPS would disappear with nothing to explain it. It is also the only mode that works on **OpenLiteSpeed**, which has no certbot plugin at all: one code path, three web servers.
- **One shared ACME challenge directory**, aliased into all nine vhost templates. Per-site document roots cannot work for node and proxy sites — they serve nothing from disk, so there is nowhere for certbot to drop the token. The alias sits ahead of the front-controller rewrite, or a WordPress site answers with its 404 page and burns an authorisation attempt.
- **Force-HTTPS never redirects `/.well-known/acme-challenge/`.** Without that exception renewal stops working and the redirect goes on pointing confidently at a certificate that has expired.
- **A redirect domain gets its own HTTPS listener.** `http://old` → `https://new` looks like it needs no certificate, but a browser that has seen HSTS for `old` refuses the plaintext hop and never reaches the redirect at all.
- **certbot's post-renewal hook reloads the web server.** Without it renewal half-works: a new certificate lands on disk while the server keeps serving the old one from memory, surfacing weeks later as an expired certificate on a site whose files are fine.
- ⚠️ **Not yet exercised against a live ACME server.** The logic and the failure classification are tested; the paths and certbot flags come from its documentation. Expect the first real issuance to need corrections.

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

### Backups

Two entry points, two permissions, on purpose. **`app_backup`** covers configuring and running backups for one application — it belongs to whoever manages that site. **`backup`** covers the cross-application history you restore *from*, because restoring overwrites live data and that deserves one screen with one set of guardrails rather than a copy inside every application.

**`GET /api/backups`** — the restore list, every application (`permission:backup`)
- Query: `filter[application_id]`, `filter[status]`, `per_page`
- Response: `{backups: [{id, application_id, type, is_safety, status, status_title, type_title, reason, reason_title, size_bytes, reference, started_at, finished_at, verified_at, created_at, created_at_human}], meta}`
- **`is_safety: true`** marks a backup taken automatically just before a restore overwrote the site. It is exempt from retention, so it will not quietly disappear — worth badging in the list, because after a bad restore it is the one row someone is actually hunting for.
- `status`: `pending` | `running` | `verifying` | `verified` | `failed`. **Only `verified` can be restored.**

**`GET|PUT /api/applications/{application}/backup-target`** — settings (`app_backup`, `manage` to write)
**`POST /api/applications/{application}/backups`** — run one now (`app_backup,manage`, throttle 6/min) → `202`

### Restore — `POST /api/backups/{backup}/restore`, `GET /api/restores`, `GET /api/restores/{restore}`

**This is the only operation in the panel that destroys data.** It replaces a live site's files and drops-and-reimports its databases. The API is deliberately awkward about it.

**`POST /api/backups/{backup}/restore`** (`permission:backup,manage`, throttle 2/min)
- Body: `confirm` (**required** — the application's domain, typed exactly; anything else is `422`), `type` (optional: `filesystem` | `database` | `full`, defaults to whatever the backup holds)
- Response `202`: `{"restore": {…}}` — the row exists before a worker picks it up, unlike a backup. "Nothing happened" and "it is about to overwrite my site" must not look the same.
- `422` when: the confirmation does not match · the backup is not `verified` · its application is gone · a restore is already running for that application · the requested `type` asks for more than the archive holds (`full` from a database-only backup would swap an empty directory over a working site).
- **The target application is taken from the backup, never from the request.** A restore cannot be aimed at a different site — that is what cloning is for, and doing it here would write one customer's database over another's.

**`GET /api/restores/{restore}`** — poll (throttle 120/min)
- `{"restore": {id, backup_id, application_id, type, type_title, status, status_title, current_step, current_step_title, step_number, total_steps, reason, reason_title, safety_backup_id, rollback_path, reference, started_at, finished_at, …}}`
- `status`: `pending` | `running` | `succeeded` | `failed`
- `current_step` (7, in order): `download_artifact`, `verify_download`, `safety_backup`, `extract_archive`, `restore_database`, `swap_files`, `restart_process`. Drive the bar from `step_number`/`total_steps`; show `current_step_title`.
- On failure `reason` is the **step that failed** (a stable key), plus `missing_backup` and `crashed`. `reason_title` is the localized explanation and says whether anything was changed. Raw stderr never reaches the client.

**Two things the UI should surface loudly:**

1. **A safety backup is always taken first** — a full backup of the current state, before anything is overwritten. It is not a checkbox and cannot be skipped: someone choosing a restore does not yet know it is the wrong one. `safety_backup_id` on the response is the way back, and it belongs on the success screen, not buried.
2. **The previous site directory is moved, not deleted.** `rollback_path` is where it went. A restore that "worked" but produced a wrong-looking site is still recoverable by hand while that exists.

**Everything before `restore_database` is non-destructive.** A failed download, a truncated archive, a corrupt tarball or a safety backup that could not be taken all leave the application exactly as it was — the localized `reason_title` for those cases says so explicitly, and the UI should not phrase them as damage.

**`GET /api/restores`** — history (`permission:backup`), paginated, same shape.

#### Building the screen

```jsonc
// POST /api/backups/12/restore   body: { "confirm": "shop.example.com", "type": "full" }
// 202
{ "restore": {
  "id": 3, "backup_id": 12, "application_id": 4,
  "type": "full", "type_title": "Files and database",
  "status": "pending", "status_title": "Queued",
  "current_step": null, "current_step_title": null,
  "step_number": null, "total_steps": 7,
  "reason": null, "reason_title": null,
  "safety_backup_id": null, "rollback_path": null,
  "reference": "5f0c…", "started_at": null, "finished_at": null
} }

// GET /api/restores/3 — mid-run
{ "restore": { "id": 3, "status": "running", "status_title": "Restoring",
  "current_step": "safety_backup",
  "current_step_title": "Backing up the current state first",
  "step_number": 3, "total_steps": 7, … } }

// GET /api/restores/3 — done
{ "restore": { "id": 3, "status": "succeeded", "status_title": "Restored",
  "current_step": null, "step_number": null,
  "safety_backup_id": 27,
  "rollback_path": "/home/siteowner/.rollback-3",
  "started_at": "04-08-2026 15:10:02", "finished_at": "04-08-2026 15:13:48" } }

// GET /api/restores/3 — failed, nothing was changed
{ "restore": { "id": 3, "status": "failed", "status_title": "Restore failed",
  "reason": "verify_download",
  "reason_title": "The downloaded backup is incomplete or corrupt, so it was not used. Nothing on the server was changed.",
  "safety_backup_id": null, "rollback_path": null, … } }

// POST with the wrong confirmation — 422
{ "message": "Type the application domain exactly to confirm the restore.",
  "errors": { "confirm": ["Type the application domain exactly to confirm the restore."] } }
```

**Flow:** `POST` → `202` → poll `GET /api/restores/{id}` every ~2s until `status` is `succeeded` or `failed`. Drive the bar off `step_number`/`total_steps` and label it with `current_step_title`; do not hardcode the seven keys, they are config.

**The confirm field.** Show the domain next to the input and label it *type `shop.example.com` to confirm* — the check is an exact string match on the application's `domain`, so a paste-the-name pattern works and anything else is a `422` on `errors.confirm`. Disabling the button until the input matches is the friendlier version of the same rule.

**Branch on `reason`, never on prose.** The keys are the seven step names plus `missing_backup` and `crashed`. `reason_title` is already localised and already states whether anything was changed — render it verbatim rather than writing your own copy, because the distinction between "nothing was touched" and "the previous state is in the safety backup" is the whole point of the message.

**On success, surface two fields prominently:**
- `safety_backup_id` — "we backed up what was there first" with a link into `/backups`. This is the undo, and it belongs on the success screen, not in a details drawer.
- `rollback_path` — the previous site directory, still on disk. Worth showing as a monospace path for someone who needs to reach for it over SSH.

**Restorable rows only.** In the backups list, offer Restore on `status: "verified"` and nothing else; anything else is a `422`. Backups with `is_safety: true` are restorable like any other and are worth badging — after a bad restore, that row is what someone is looking for.

**One restore at a time per application.** A second `POST` while one is running is `422` on `errors.backup`; keep the button disabled while a poll shows `pending` or `running`.

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
- `types` (18): `application`, `cronjob`, `database`, `disk_cleaner`, `fail2ban`, `firewall`, `git_account`, `log`, `node`, `panel_update`, `permission`, `php`, `role`, `server`, `service`, `setting`, `system_user`, `user`
- `actions` (98 verbs, deduped across types) — too many to be worth hardcoding; this is exactly why the endpoint exists. Notable ones for the panel-update screen: `started`, `failed`.
