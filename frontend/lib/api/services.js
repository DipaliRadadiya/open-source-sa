import { api } from "@/lib/api/client";

/**
 * PUT /api/services/{key} — run one systemctl action. Responds with the
 * refreshed service; callers re-render the server component so the table stays consistent.
 */
export function runServiceAction(key, action) {
  return api.put(`/services/${encodeURIComponent(key)}`, { action });
}

/**
 * The whole list again, for the usage poll. `cpu_percent` is null until a
 * second sample exists, so CPU only appears after polling.
 */
export function listServices({ signal } = {}) {
  return api.get("/services", { signal });
}

/** Validate the service's configuration. Read-only; never reloads. */
export function testServiceConfig(key) {
  return api.post(`/services/${encodeURIComponent(key)}/config-test`);
}

// PHP versions and ini live in lib/api/php.js, behind the `php` permission.
