import { api } from "@/lib/api/client";
import { parsedOrThrow } from "@/lib/api/parse-response";
import { panelUpdateStateSchema, panelUpdateRunSchema } from "@/lib/schemas/panel-update";

export async function fetchPanelUpdateState() {
  const res = await api.get("/admin/panel-update");
  return parsedOrThrow(panelUpdateStateSchema, res.data?.panel_update, "fetchPanelUpdateState");
}

export async function refreshPanelUpdateState() {
  const res = await api.get("/admin/panel-update", { params: { refresh: true } });
  return parsedOrThrow(panelUpdateStateSchema, res.data?.panel_update, "refreshPanelUpdateState");
}

export async function startPanelUpdate({ dryRun = false } = {}) {
  const res = await api.post("/admin/panel-update", null, {
    params: dryRun ? { dry_run: true } : undefined,
  });
  return parsedOrThrow(panelUpdateRunSchema, res.data?.panel_update, "startPanelUpdate");
}

export async function fetchPanelUpdateRun(id) {
  const res = await api.get(`/admin/panel-update/${id}`);
  return parsedOrThrow(panelUpdateRunSchema, res.data?.panel_update, "fetchPanelUpdateRun");
}
