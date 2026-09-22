import { cache } from "react";
import { z } from "zod";
import { read } from "@/lib/api/read";
import { panelUpdateStateSchema } from "@/lib/schemas/panel-update";

/**
 * Panel self-update state (GET /admin/panel-update). Admin-only. Note
 * `available.checked: false` is NORMAL — the release host was unreachable —
 * and still 200, so it is not a failure.
 *
 * Through `read()` rather than its own try/catch: a bare `null` was returned
 * for a 403, a 500, a dead request and a shape mismatch alike, so the update
 * screen could only say "could not be loaded" and the journal got nothing.
 */
export const getPanelUpdate = cache(async function getPanelUpdate() {
  const { data, failed, status, failure, message, debug } = await read(
    "/admin/panel-update",
    z.object({ panel_update: panelUpdateStateSchema }),
  );

  return { state: data?.panel_update ?? null, failed, status, failure, message, debug };
});
