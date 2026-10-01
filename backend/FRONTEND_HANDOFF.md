# Frontend handoff — backend changes

What the backend changed, and what the frontend has to do about it. Newest
first. `API_REFERENCE.md` remains the contract; this file only says **what
moved and why it matters**, so nobody has to diff the reference to find out.

---

## 2026-10-01

### 0. File manager: folder sizes without a click (new endpoint)
- `GET /api/applications/{id}/files/sizes?path=<dir>` returns the size of **every folder in that directory** at once (see API_REFERENCE). Call it right after the listing loads, without waiting on it, and fill the size column by folder name. Hidden folders are included; a name missing from `sizes` means "not measured", so show "—".
- Show `measured_at` (e.g. "measured 3 min ago") and a **refresh** button that calls it with `refresh=1`. Answers are kept 5 minutes; panel changes clear them immediately.
- A folder too big to measure in 60 seconds gives the usual timed-out error; keep the dashes and offer refresh.
- With sizes in hand, the size column can be **sortable** (biggest first). A share bar per folder is `size / total.size`.
- The old per-folder "Calculate" (`/files/size`) still works; it can go once this is in.

### 1. PHP settings → "Additional directives": hint text is now wrong (commit 3c3fe7f9)
- `frontend/messages/*.json` key `hints.directives` (en ~line 5034) says: "One per line, in PHP-FPM pool form: php_admin_value[name] = value. **Anything here wins over the fields above.**"
- Now: one PHP setting per line, `name = value` or `php_[admin_]value|flag[name] = value`.
  - Settings that have their own field are **refused with a 422**: memory_limit, upload_max_filesize, post_max_size, max_execution_time, max_input_time, max_input_vars, allow_url_fopen, session.save_path, session.gc_maxlifetime, error_log, log_errors, date.timezone, auto_prepend_file, open_basedir, disable_functions.
  - `extension =` and `zend_extension =` lines are also refused.
- Suggested hint: "One PHP setting per line, e.g. display_errors = Off. Settings with their own field above, and extensions, are set there instead."
- The 422 comes back on field `additional_directives` with a translated message that names the line (keys directive_invalid / directive_managed / directive_extension). Show the API's message under the textarea.
- A site that already has such a line saved gets this 422 on its next save until the line is removed.

### 2. Admin → Roles → Delete role: show the API's refusal (commit 00a1e52e)
- `DELETE /api/admin/roles/{id}` now returns 422 when the role is some user's **only** role:
  `{"errors": {"role": ["Support Staff is the only role of alice, bob. Give them another role first, then delete it."]}}` (8 locales).
- `components/admin/roles/delete-role-dialog.jsx` always shows `t("toast.deleteFailed")` ("Could not delete this role."), so the user never sees why. Use the API message instead, e.g. `apiMessage(error, t("toast.deleteFailed"))` as other dialogs do.
- The dialog text "Users assigned this role will be unassigned" is still correct for users who have another role.

### 3. Deployment — FYI, probably no change needed (commits 7782b496, 18f16dd4)
- `settings.default_deploy_script` no longer contains `git pull origin {branch}`: it failed on private repos, and the panel already fetches the code before the script runs. The PHP default is now:
  `cd {path}` followed by `if [ -f composer.json ]; then composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader; fi`
- `settings.deploy_script_customised` is now false when the saved script equals the default (ignoring line endings and trailing spaces).
- New failure reason `script_git_auth` (in `failed_reason` / `failed_reason_title`, translated). It appears when a saved script's `git pull` can't log in, and the title is ready to show as-is.

### 4. Rate limit (commit 66b09416)
- API 429 responses now carry a translated message with the wait, e.g. "Too many attempts. Try again in 59 seconds." If any screen shows its own fixed rate-limit text, the API's message is better.

---

## 2026-09-30

### AI Bot Blocker — show the robots.txt lines

`GET /ai-bot-policies` now also returns:

```json
"robots_txt": { "note": "<localised sentence>", "lines": "User-agent: Google-Extended\nDisallow: /\n\nUser-agent: Applebot-Extended\nDisallow: /\n" }
```

and each policy has `robots_txt_recommended` (true for every policy except
`allow_all`). Please show `note` + `lines` (a copyable code block) on the Bot
Blocker screen when the selected policy has `robots_txt_recommended: true`.

Why: Google (Gemini) and Apple train on what Googlebot/Applebot fetch. Their only
opt-out is a robots.txt token; no user-agent block can do it. `Google-Extended`
and `Applebot-Extended` were in the training list doing nothing, and are gone
from `blocked_bots`. Typing either into the custom block list now returns 422
with an explanation.

---

## 2026-08-12

### 1. 8G Firewall — renamed, and hidden where it cannot work

**Renamed.** `nav.app_firewall` is now "8G Firewall" (localised in all 8). You
already render nav labels from the API, so nothing to do — but the screen's own
heading should match.

**Update 2026-09-30: OpenLiteSpeed supports it now.** The 8G rules are rendered
into each OLS site's vhconf, so `app_firewall` appears on OLS servers like on
nginx and Apache, with the same endpoints and fields. One difference: in detect
mode the `waf_detect` log is the site's access log filtered to the marked lines,
and `DELETE …/logs/waf_detect` answers **422** there (clearing it would empty the
access log) — hide or disable that button when it fails, the message says why.
The paragraph below describes the mechanism, which still applies to any future
web server without a WAF.

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
