import { api } from "@/lib/api/client";
import { parsedOrThrow } from "@/lib/api/parse-response";
import { panelUpdateStateSchema, panelUpdateRunSchema } from "@/lib/schemas/panel-update";

// No fresh release-host lookup. Also the recovery probe: a POST may have created a run whose reply was lost.
export async function fetchPanelUpdateState() {
  const res = await api.get("/admin/panel-update");
  return parsedOrThrow(panelUpdateStateSchema, res.data?.panel_update, "fetchPanelUpdateState");
}

// "Check now" — bypasses the 60-min availability cache.
export async function refreshPanelUpdateState() {
  const res = await api.get("/admin/panel-update", { params: { refresh: true } });
  return parsedOrThrow(panelUpdateStateSchema, res.data?.panel_update, "refreshPanelUpdateState");
}

// dryRun replaces every mutating command with echo; the run is still fully reported.
export async function startPanelUpdate({ dryRun = false } = {}) {
  const res = await api.post("/admin/panel-update", null, {
    params: dryRun ? { dry_run: true } : undefined,
  });
  return parsedOrThrow(panelUpdateRunSchema, res.data?.panel_update, "startPanelUpdate");
}

// May THROW mid-update (503 and refused connections are normal); retry with backoff.
export async function fetchPanelUpdateRun(id) {
  const res = await api.get(`/admin/panel-update/${id}`);
  return parsedOrThrow(panelUpdateRunSchema, res.data?.panel_update, "fetchPanelUpdateRun");
}
