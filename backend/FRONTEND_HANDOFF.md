# Frontend handoff — backend changes

What the backend changed, and what the frontend has to do about it. Newest
first. `API_REFERENCE.md` remains the contract; this file only says **what
moved and why it matters**, so nobody has to diff the reference to find out.

---

## 2026-08-12

### 1. 8G Firewall — renamed, and hidden where it cannot work

**Renamed.** `nav.app_firewall` is now "8G Firewall" (localised in all 8). You
already render nav labels from the API, so nothing to do — but the screen's own
heading should match.

**Hidden on unsupported web servers.** OpenLiteSpeed has no WAF rules in its
vhost templates, so on an OLS server `app_firewall` is **dropped from the
application's feature list**. It never appears in
`GET /permissions?level=application&application_id=…`, and its endpoints answer
**404**. Same treatment a WordPress site gets for the Deployment screen.

Nothing to build for this — if you drive the sidebar from permissions (you do),
the item simply is not there. `waf_supported` on the application resource and
on `GET /waf-options` exists only if you want to *explain* the absence rather
than silently omit it.

**The old docs were wrong. Recheck your bindings:**

| Field | Doc used to say | Actually |
|---|---|---|
| `waf_categories` | object of booleans | **flat array** of enabled values |
| category values | `sql_injection`, `xss`, `spam`, `bad_js` | `query_string`, `request_uri`, `user_agent`, `referrer`, `cookie`, `method` — those six only |
| `waf_exceptions` / `waf_custom_rules` | array of `{kind, value}` | **array of plain strings** |

**Omitting a field on `PUT` leaves it unchanged** — it does not reset. Send `[]`
to clear. Absent once meant "all six on", which silently re-enabled a category
someone had switched off to fix a false positive.

`waf_exceptions` / `waf_custom_rules` use `whenLoaded`: **absent**, not empty,
when the relation was not loaded. Do not read missing as "none".

**Please design the detect → enforce flow, not just a toggle.** `detect` blocks
nothing and logs what *would* have been blocked to the `waf_detect` log key,
which appears in the logs list **only while mode is `detect`**. The intended
path is detect → read the log → add exceptions → enforce. A UI that jumps
straight to enforce skips the step that makes a WAF safe to switch on — and
detect mode is the thing RunCloud, GridPane and ServerAvatar do not have.

### 2. fail2ban install is now observable

`GET /fail2ban` gains an `install` object, `null` once fail2ban is on disk:

```json
{"fail2ban": {
  "installed": false,
  "install": {
    "status": "installing",
    "reason": null, "reason_title": null, "reference": null,
    "started_at": "12-08-2026 15:20:11", "finished_at": null
  }
}}
```

`status` is `installing` or `failed`. On failure, `reason` is a stable code
(`package_not_found`, `apt_lock`, `network`, `no_space`, `worker`, `unknown`)
and `reason_title` is it rendered in the viewer's locale. `reference` locates
the server-ops log entry for support.

**This needs UI.** `installed` is a boolean derived from the package being on
disk, and apt is allowed **ten minutes**. A screen built on `installed` alone
shows nothing for ten minutes and nothing at all when it fails. Three states:

- `install.status === 'installing'` → progress, keep polling
- `install.status === 'failed'` → show `reason_title`, offer retry, show
  `reference` for support
- `install === null && installed` → done

### 3. Rate limits — polling will not 429 any more

Every endpoint a screen polls while a long job runs is now **outside the global
per-user budget**, on its own allowance (600/min, keyed per user and per polled
resource):

`GET /applications/{id}` · `…/sidebar` · `…/deployments/{deployment}` ·
`/server/sync/{run}` · `/admin/panel-update/{id}` · `/php` · `/node` ·
`/setup` · `/databases/status/{engine}` · `/backups/{backup}` ·
`/restores/{restore}` · `/clones/{clone}` · `/fail2ban`

Before this, polling spent the same budget as everything else the user was
doing, so a long install ended in "Too Many Attempts" — which reads as the
install having failed. If you added backoff or reduced poll rates to work
around that, you can take it out.

**Still on the ordinary budget** (180/min per user): everything else. If a
screen polls something not in the list above, tell us rather than adding
backoff — it probably belongs on the list.

The central panel has its own 3000/min budget, so its calls no longer compete
with a human's.

### 4. Requests the API would have rejected

Documented wrongly before, now corrected in `API_REFERENCE.md`:

| Endpoint | Was documented as | Actually |
|---|---|---|
| `GET …/files/search` | `?search=` | `?q=`, **required** |
| `POST …/certificate` | `cert`, `key`, `domains[]` | `certificate`, `private_key`; **no `domains` field exists** |
| `PUT /fail2ban` | per-jail objects | flat server-wide thresholds; `jails` is an on/off map |
| `POST /databases` | no `create_user` | optional nested user object |
| `PUT /admin/users/{id}/password` | that path | `/reset-password`, needs `password_confirmation`, returns 204 |
| `GET /admin/users/{id}`, `GET /admin/roles/{id}` | documented | **do not exist** |

**`PUT /fail2ban` has a lockout guard worth handling.** Enabling the SSH jail
when the caller's own IP is not in `ignore_ips` answers **422** — that request
can lock someone out of their own server. Catch it, warn, and offer either "add
my IP to the ignore list" or resubmit with `acknowledged: true`. Undocumented,
it reads as a random validation failure.

### 5. Environment editor can be emptied

`PUT /applications/{id}/environment` accepts `raw: ""` — clearing the file is a
legitimate save. It used to answer "The raw field is required" about the field
the user had just deliberately emptied. Omitting `raw` entirely still 422s.

### 6. File manager response shape (from earlier today)

If you have not already picked this up: `modified` → `modified_at`,
`permissions` → `mode`, and `size_human` / `modified_at_human` / `owner` /
`group` / `link_target` / `link_broken` are all present. `type` is `dir`, not
`directory`. On a symlink, `mode`/`owner`/`group` are `null`.

---

## Two backend gaps, so you do not build against them

- **`POST /central/enable` returns the token masked.** Nothing in the API ever
  exposes the raw value a user must paste into the central panel, so a "copy
  token" button copies asterisks. Do not build that flow yet.
- **`has_staging`** is on the application resource but only one of the two
  endpoints returning an application loads the relation. Branch on the staging
  endpoint returning `null` instead.
