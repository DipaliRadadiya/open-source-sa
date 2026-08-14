import { api } from "@/lib/api/client";

/**
 * Start a run. Throttled to 10/min, and only one run may be live at a time — a
 * second POST while one is running is a 422 carrying `errors.sync`, not a
 * queued run. The caller must surface that message rather than retrying.
 *
 * `mode` is sent explicitly even for a preview. The backend defaults an omitted
 * mode to preview on purpose, but relying on that default from here would mean
 * a typo in this file could start the writing one.
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
 * A run and its items after a cursor.
 *
 * This is an append-only feed, not a re-fetch: `since` is the id of the last
 * item already held, and at most 500 come back per call. Passing 0 every poll
 * would re-send every row to add three, and on a box with hundreds of vhosts
 * that is the difference between a live list and a stalled one.
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
 * Dismiss one discovered thing, for good.
 *
 * This is the only per-item control the API has: `only` takes resource types,
 * never item ids, so "adopt these six" is not expressible and excluding the
 * rest one at a time is the whole mechanism. An ignored key stops appearing in
 * later runs unless a run asks for `include_ignored`.
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
