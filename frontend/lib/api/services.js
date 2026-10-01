import { api } from "@/lib/api/client";

// Responds with the refreshed service; callers re-render so the table stays consistent.
export function runServiceAction(key, action) {
  return api.put(`/services/${encodeURIComponent(key)}`, { action });
}

/** `cpu_percent` is null until a second sample exists, so CPU only appears after polling. */
export function listServices({ signal } = {}) {
  return api.get("/services", { signal });
}

/** Validate the service's configuration. Read-only; never reloads. */
export function testServiceConfig(key) {
  return api.post(`/services/${encodeURIComponent(key)}/config-test`);
}

// PHP versions and ini live in lib/api/php.js, behind the `php` permission.
