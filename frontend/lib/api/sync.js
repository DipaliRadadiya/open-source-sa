import { api } from "@/lib/api/client";

// Throttled to 10/min; a POST while one runs is a 422 (`errors.sync`): surface it, do not
// retry. `mode` is always explicit so a mistake cannot start a writing run.
export function startSync({ mode = "preview", only = [], includeFirewall = false, includeIgnored = false } = {}) {
  return api.post("/server/sync", {
    mode,
    only,
    include_firewall: includeFirewall,
    include_ignored: includeIgnored,
  });
}

// Append-only: `since` is the last item id already held; at most 500 per call.
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

// Permanent; the only per-item control. Ignored keys reappear only with `include_ignored`.
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

// 500 with per-step results when a step failed, so it is never read as done.
export function completeSyncHandover() {
  return api.post("/server/sync/handover");
}
