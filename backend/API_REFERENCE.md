# ServerAvatar OSS Backend — API Reference

Base URL: `https://sv-oss.167-233-229-184.nip.io/api`
Auth: `Authorization: Bearer <token>` header (returned by register/login), or cookie-session for stateful frontend domains (`SANCTUM_STATEFUL_DOMAINS`). Admin-only routes additionally require the user's `role` to be `admin`.
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
- Response `201`: `{"user": {id, name, username, role, created_at, created_at_human}, "token": string}` — also sets a session cookie if the request came from a stateful domain. Registration-closed returns `403` (`{"message": ...}`, no `errors`).

### `POST /auth/login`
- Body: `username` (string, required), `password` (string, required)
- Rate limit: 5/min per username+IP
- Response `200`: same shape as register. `422` with `{"errors": {"username": ["These credentials do not match our records."]}}` on bad credentials.

---

## Authenticated (any logged-in user — `Authorization: Bearer <token>`)

### `POST /auth/logout`
Revokes the current token (or session, if cookie-authenticated). No body. Response `204`.

### `GET /auth/me`
Current user, plus impersonation state. Response: `{"user": {id, name, username, role, created_at, created_at_human}, "impersonated_by": {id, username}|null}` — `impersonated_by` is non-null only during an impersonated session (show a banner); otherwise `null`.

### `PUT /auth/password`
Self password change. Also revokes all existing tokens and issues a new one (so the caller must re-store the returned token).
- Body: `current_password` (string, required, must match), `password` (string, required, confirmed, min 10 + mixed case + numbers), `password_confirmation`
- Response `200`: `{"token": string}`

### `POST /auth/stop-impersonating`
Ends an impersonated session — revokes the impersonation token (the admin's own token is untouched; the frontend switches back to it). Must be called with the impersonation token; a normal session returns `422`. Response `204`.

### `GET /activity-log`
The caller's **own** activity history only (not admin-wide — see `/admin/activity-log` for that). No `user` field per entry — it's always the caller, so it's omitted as redundant (unlike the admin version below, which spans multiple users and needs it).
- Query: `per_page` (10|20|50|100, default 10)
- Response: `{"activity_log": [{id, action, description, created_at, created_at_human}], "meta": {...}}`

### `GET /permissions`
Permission items the caller can see (admin sees all with full access; regular users see only what they've been granted, directly or via their assigned Role).
- Query: `level` (string, optional — filters to one permission level, e.g. `server`)
- Response: `{"permissions": [{level, sub_level, name, title, icon, url, permissions: {view, manage}}]}`

### `GET /permissions/check`
Same as above but `level` is **required** (used for a targeted single-level check).
- Query: `level` (string, required)
- Response: same shape as `/permissions`

---

## Admin only (`role: admin`, plus `Authorization: Bearer <token>`)

### `GET /admin/dashboard`
Aggregate stats. No params.
- Response: `{"dashboard": {"users": {total, admin, user}, "roles": {total}, "activity": {today, total}}}`

### Users — `GET|POST /admin/users`, `PUT|DELETE /admin/users/{user}`

**`GET /admin/users`** — list/search/filter
- Query: `search` (string, optional — matches `name` or `username`), `filter[role]` (`admin`|`user`, optional), `per_page` (10|20|50|100)
- Response: `{"users": [{id, name, username, role, created_at, created_at_human}], "meta": {...}}`

**`POST /admin/users`** — create
- Body: `name` (required), `username` (required, alpha_dash, unique), `password` + `password_confirmation` (required, min 10 + mixed case + numbers), `role` (required, `admin`|`user`)
- Response `201`: `{"user": {id, name, username, role, created_at, created_at_human}}`

**`PUT /admin/users/{user}`** — edit
- Path: `user` = user ID
- Body: `name` (required), `username` (required, alpha_dash, unique ignoring self), `role` (required, `admin`|`user`)
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

### `PUT /admin/users/{user}/role`
Assigns (or unassigns, via `null`) a named Role to a user.
- Path: `user` = user ID
- Body: `role_id` (integer, nullable — `null` unassigns, or must `exist` in `roles`)
- Response `204`

### Roles — `GET|POST /admin/roles`, `PUT|DELETE /admin/roles/{role}`

**`GET /admin/roles`** — list (not paginated, returns all)
- Response: `{"roles": [{id, name, slug, description, permissions: [...], created_at, created_at_human}]}`

**`POST /admin/roles`** — create
- Body: `name` (required, string, max 255 — duplicates checked case-insensitively via normalized slug), `description` (nullable, string, max 1000), `permissions` (array, optional) — each item: `level`, `name`, `view`, `manage` (all required if `permissions` sent)
- Response `201`: `{"role": {id, name, slug, description, permissions: [{level, name, title, permissions: {view, manage}}], created_at, created_at_human}}`

**`PUT /admin/roles/{role}`** — update
- Path: `role` = role ID
- Body: same as create (name uniqueness ignores this role itself)
- Response `200`: same shape as create

**`DELETE /admin/roles/{role}`** — delete
- Path: `role` = role ID
- Any users with this role assigned have `role_id` nulled (not deleted).
- Response `204`

### `GET /admin/activity-log`
Admin-wide activity — every user's actions, not just the caller's.
- Query: `filter[user_id]` (integer, must exist), `filter[type]` (string, e.g. `user`|`role` — matches everything under that prefix), `filter[action]` (string, exact match, e.g. `user.created`), `search` (string — free-text on `action` or the acting user's `name`/`username`), `per_page` (10|20|50|100)
- Response: `{"activity_log": [{id, type, action, description, user: {id, username}|null, created_at, created_at_human}], "meta": {...}}`

### `GET /admin/activity-log/filters`
Distinct `type`/`action` values, for populating a frontend filter dropdown. Sourced from the known translation keys (`lang/activity.php`), not a `DISTINCT` query on actual log rows — so it's fully populated even on a fresh install with zero activity yet.
- Response: `{"types": string[], "actions": string[]}`

---

## Enums / fixed values

- `role` (on `User`): `"admin"` | `"user"`
- Permission `level` (seeded so far): `"server"` — 12 items: `dashboard`, `application`, `database`, `system_user`, `firewall`, `cronjob`, `fail2ban`, `logs`, `service`, `setting`, `disk_cleaner`, `activity_log`

---

## Known activity-log `type`/`action` values (for filtering)

Prefer `GET /admin/activity-log/filters` for these at runtime, but for reference:
- `types`: `role`, `user`
- `actions`: `user.registered`, `user.logged_in`, `user.password_changed`, `user.password_reset_by_admin`, `user.created`, `user.updated`, `user.deleted`, `user.permissions_updated`, `user.role_assigned`, `user.impersonation_started`, `user.impersonation_stopped`, `role.created`, `role.updated`, `role.deleted`
