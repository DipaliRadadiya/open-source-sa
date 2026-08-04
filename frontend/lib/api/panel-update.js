import { api } from "@/lib/api/client";
import { panelUpdateStateSchema, panelUpdateRunSchema } from "@/lib/schemas/panel-update";

// "Check now" — bypasses the 60-min availability cache.
export async function refreshPanelUpdateState() {
  const res = await api.get("/admin/panel-update", { params: { refresh: true } });
  return panelUpdateStateSchema.parse(res.data?.panel_update);
}

// Start an update. dryRun runs the real script with every mutating command
// replaced by echo — nothing changes, but the run is fully reported.
export async function startPanelUpdate({ dryRun = false } = {}) {
  const res = await api.post("/admin/panel-update", null, {
    params: dryRun ? { dry_run: true } : undefined,
  });
  return panelUpdateRunSchema.parse(res.data?.panel_update);
}

// Poll one run. Callers must tolerate this THROWING mid-update: restart_services
// and maintenance mode make the panel return 503 / refuse connections, which is
// normal progress — retry with backoff and resume once it answers again.
export async function fetchPanelUpdateRun(id) {
  const res = await api.get(`/admin/panel-update/${id}`);
  return panelUpdateRunSchema.parse(res.data?.panel_update);
}
