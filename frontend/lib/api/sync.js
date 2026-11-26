import { api } from "@/lib/api/client";

// mode is always sent so a mistake can't start a writing run.
export function startSync({ mode = "preview", only = [], includeFirewall = false, includeIgnored = false } = {}) {
  return api.post("/server/sync", {
    mode,
    only,
    include_firewall: includeFirewall,
    include_ignored: includeIgnored,
  });
}

export function getSyncRun(runId, { since = 0, signal } = {}) {
  return api.get(`/server/sync/${runId}`, { params: { since }, signal });
}

export function getSyncIgnores({ signal } = {}) {
  return api.get("/server/sync/ignores", { signal });
}

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

export function completeSyncHandover() {
  return api.post("/server/sync/handover");
}
