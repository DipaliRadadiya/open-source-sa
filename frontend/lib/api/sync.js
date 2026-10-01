import { api } from "@/lib/api/client";

/**
 * Start a run. Throttled to 10/min; a second POST while one is running is a
 * 422 with `errors.sync`. Surface it, do not retry.
 *
 * `mode` is always sent explicitly so a mistake here cannot start a writing run.
 */
export function startSync({ mode = "preview", only = [], includeFirewall = false, includeIgnored = false } = {}) {
  return api.post("/server/sync", {
    mode,
    only,
    include_firewall: includeFirewall,
    include_ignored: includeIgnored,
  });
}

/**
 * A run and its items after a cursor. Append-only: `since` is the last item id
 * already held; at most 500 come back per call.
 */
export function getSyncRun(runId, { since = 0, signal } = {}) {
  return api.get(`/server/sync/${runId}`, { params: { since }, signal });
}

/** The most recent run, for a screen reopened after a refresh. No items. */
export function getLatestSync({ signal } = {}) {
  return api.get("/server/sync/latest", { signal });
}

export function getSyncIgnores({ signal } = {}) {
  return api.get("/server/sync/ignores", { signal });
}

/**
 * Permanently dismiss one discovered item; the only per-item control (`only`
 * takes resource types, not ids). Ignored keys reappear only with
 * `include_ignored`.
 */
export function ignoreSyncItem({ resourceType, resourceKey, note }) {
  return api.post("/server/sync/ignores", {
    resource_type: resourceType,
    resource_key: resourceKey,
    note: note || undefined,
  });
}

export function unignoreSyncItem(ignoreId) {
  return api.delete(`/server/sync/ignores/${ignoreId}`);
}
