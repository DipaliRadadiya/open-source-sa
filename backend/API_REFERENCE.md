# ServerAvatar OSS Backend — API Reference

> 🔗 **Live version:** <https://sv-oss.167-233-229-184.nip.io/docs/api-reference> (always current — bookmark it). Raw markdown: `/docs/api-reference.md`

Base URL: `https://sv-oss.167-233-229-184.nip.io/api`
Auth: `Authorization: Bearer <token>` header (returned by register/login), or cookie-session for stateful frontend domains (`SANCTUM_STATEFUL_DOMAINS`). Admin-only routes additionally require `is_admin: true`.
RBAC: `is_admin` (bool) gates the admin area only. Feature permissions are **pure role-based** — a user's effective permissions are the deduped OR-union across ALL their assigned roles + any direct grants (no admin bypass). Every user has **≥1 role**. The first registered user is `is_admin` + the protected **Administrator** role (holds every permission, `is_system`, cannot be deleted/renamed/edited).
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

### `PUT /auth/password`
Self password change. Also revokes all existing tokens and issues a new one (so the caller must re-store the returned token).
- Body: `current_password` (string, required, must match), `password` (string, required, confirmed, min 10 + mixed case + numbers), `password_confirmation`
- Response `200`: `{"token": string}`

### `POST /auth/stop-impersonating`
Ends an impersonated session — revokes the impersonation token (the admin's own token is untouched; the frontend switches back to it). Must be called with the impersonation token; a normal session returns `422`. Response `204`.

### `GET /activity-log`
The caller's **own** activity history only (not admin-wide — see `/admin/activity-log` for that). No `user` field per entry — it's always the caller, so it's omitted as redundant (unlike the admin version below, which spans multiple users and needs it).
- Query: `per_page` (10|20|50|100, default 10)
- Response: `{"activity_log": [{id, type, action, description, created_at, created_at_human}], "meta": {...}}` (`type` = entity, `action` = verb, `description` = composed sentence)

### `GET /permissions`
Permission items the caller can see — the **deduped OR-union** across all their assigned roles + direct grants (each permission appears once; `manage`/`view` are true if any source grants them). Pure role-based, no admin bypass: an admin sees everything only because they hold the Administrator role.
- Query: `level` (string, optional — filters to one permission level, e.g. `server`)
- Response: `{"permissions": [{level, sub_level, name, title, icon, url, permissions: {view, manage}}]}`

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
Admin "login as user" — issues a **1-hour** token that authenticates as the target user (the target's own permissions still gate everything). Blocked (`422`) for self and for admin→admin.
- Path: `user` = user ID (must be a non-admin, not self)
- Response `201`: `{"user": {...target...}, "token": string, "impersonated_by": {id, username}}`
- Frontend: store this token and use it as the active session; `GET /auth/me` will report `impersonated_by` (show a banner); call `POST /auth/stop-impersonating` (with this token) to end and switch back to the admin's own token.

### `PUT /admin/users/{user}/permissions`
Direct permission grants for a user (in addition to whatever their assigned Role grants — union of both).
- Path: `user` = user ID
- Body: `permissions` (array, required) — each item: `level` (string, required), `name` (string, required), `view` (bool, required), `manage` (bool, required — implies `view: true` even if `view: false` was sent)
- Unknown `(level, name)` pairs are silently skipped (not an error).
- Response `204`

### `PUT /admin/users/{user}/roles`
Syncs the user's assigned roles (many-to-many).
- Path: `user` = user ID
- Body: `role_ids` (array, **required, min 1** — each must exist in `roles`; a user can hold multiple roles and can never be left with zero)
- Response `204`

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
Requires `cronjob` permission (`view` to read, `manage` to mutate). Each job runs **as an OS user** — a panel System User **or** a default/unmanaged account (`root`, `www-data`, …). Materialised as **one file per job** under `/etc/cron.d`, named `<slug>` where `slug` is a stable, unique identifier derived from the name (in the filename for easy identification; **migration-safe** — it travels with the row, unlike the auto-increment id). A rename regenerates the slug and relocates the file. 6-field format with the run-as user column; non-destructive (we never touch a user's personal crontab). Only `active` jobs are written to disk.

**`GET /api/cronjobs/schedule-presets`** — schedule presets for the frontend dropdown (single source of truth, localized labels).
- Response: `{"presets": [{key, label, expression}, …]}` — `custom` has `expression: null` (UI shows a raw field). Keys: `every_minute, every_5_minutes, every_15_minutes, every_30_minutes, hourly, twice_daily, daily, weekly, monthly, custom`.

**`GET /api/cronjobs/command-presets`** — framework command shortcuts. One click fills the `command` (and a recommended `expression`).
- Response: `{"presets": [{key, label, command, expression}, …]}` — `command` contains a `{path}` placeholder the frontend swaps for the selected app's directory; `custom` has `command: null` + `expression: null`. Keys: `laravel, wordpress, moodle, joomla, nextcloud, craftcms, php_script, custom` (Laravel included for custom-PHP/Laravel apps). Localized labels. (Interim source; moves onto SiteType definitions when the Application feature lands.)

**`GET /api/cronjobs`** — list (paginated)
- Query: `filter[system_user_id]`, `filter[username]`, `filter[active]` (bool), `per_page` (10|20|50|100).
- Response: `{"cronjobs": [{id, name, slug, username, system_user: {id, username}|null, command, expression, active, created_at, created_at_human}], "meta": {...}}` — `slug` is the stable identifier (also the cron.d filename key).

**`GET /api/cronjobs/{cronjob}`** → `{"cronjob": {...}}`

**`POST /api/cronjobs`** — create (writes a `/etc/cron.d/{slug}` file when active; `slug` auto-generated from `name`, unique)
- Body: `name` (required, **unique** — a duplicate name → `422`), **either** `system_user_id` (a panel System User) **or** `username` (any OS user; `required_without:system_user_id`, linux-name rules), `command` (required, max 1000), `expression` (required, valid cron — else `422`), `active` (optional bool, default true).
- The target user must **exist on the server** (`getent passwd`) → else `422` on `username`.
- Response `201`: `{"cronjob": {...}}`.

**`PUT /api/cronjobs/{cronjob}`** — update `name` / `command` / `expression` / `active` (run-as user is fixed at create — delete + recreate to change it). Rewrites or removes the cron.d file accordingly. Response `200`: `{"cronjob": {...}}`.

**`DELETE /api/cronjobs/{cronjob}`** → `204`. Removes the cron.d file. (Deleting the owning System User cascade-deletes its cron jobs.)

On any OS-op failure → `500 {message, reference}` and the DB change is rolled back (no DB↔disk drift).

---

## Enums / fixed values

- `is_admin` (on `User`): boolean.
- Permission `level` (seeded so far): `"server"` — 12 items: `dashboard`, `application`, `database`, `system_user`, `firewall`, `cronjob`, `fail2ban`, `logs`, `service`, `setting`, `disk_cleaner`, `activity_log`

---

## Known activity-log `type`/`action` values (for filtering)

Prefer `GET /admin/activity-log/filters` for these at runtime (returns `{types: [...], actions: [...]}`), but for reference — `type` and `action` are separate values:
- `types`: `cronjob`, `role`, `system_user`, `user`
- `actions` (verbs, deduped across types): `registered`, `logged_in`, `password_changed`, `password_reset_by_admin`, `created`, `updated`, `deleted`, `permissions_updated`, `role_assigned`, `impersonation_started`, `impersonation_stopped`, `create_failed`, `delete_failed`, `ssh_key_added`, `ssh_key_removed`, `password_set`, `password_failed`, `sudo_enabled`, `sudo_disabled`, `shell_changed`, `ssh_enabled`, `ssh_disabled`
