import { cache } from "react";
import { serverFetch } from "@/lib/api/server-fetch";
import { panelUpdateStateSchema } from "@/lib/schemas/panel-update";

/**
 * Panel self-update state (GET /admin/panel-update). Admin-only. Returns null
 * on failure so the page renders a graceful fallback. Note `available.checked:
 * false` is NORMAL (the release host was unreachable) and still 200 — not a
 * reason to return null.
 */
export const getPanelUpdate = cache(async () => {
  try {
    const res = await serverFetch("/admin/panel-update");
    if (!res.ok) return null;
    const json = await res.json();
    const parsed = panelUpdateStateSchema.safeParse(json?.panel_update);
    return parsed.success ? parsed.data : null;
  } catch {
    return null;
  }
});
