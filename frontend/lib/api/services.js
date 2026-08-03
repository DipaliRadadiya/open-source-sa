import { api } from "@/lib/api/client";

/**
 * PUT /api/services/{key} — run one systemctl action.
 * Responds with the refreshed service; the caller re-runs the server component
 * rather than patching state, so the whole table stays consistent with the box.
 */
export function runServiceAction(key, action) {
  return api.put(`/services/${encodeURIComponent(key)}`, { action });
}

/**
 * The whole list again, for the usage poll. `cpu_percent` is null until a second
 * sample exists, so CPU only becomes real once this has run at least twice —
 * polling isn't a refresh nicety here, it's the only way the value exists.
 */
export function listServices({ signal } = {}) {
  return api.get("/services", { signal });
}

/**
 * Validate the service's configuration. Read-only — it never reloads, which is
 * the point: you check whether a change is safe *before* applying it.
 */
export function testServiceConfig(key) {
  return api.post(`/services/${encodeURIComponent(key)}/config-test`);
}

// PHP versions and their ini moved to lib/api/php.js with the rest of PHP:
// they are one feature behind the `php` permission now, and editing an ini from
// the Services screen would have 403'd for anyone who can manage services but
// not PHP.
