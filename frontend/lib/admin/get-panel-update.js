import { cache } from "react";
import { z } from "zod";
import { read } from "@/lib/api/read";
import { panelUpdateStateSchema } from "@/lib/schemas/panel-update";

/**
 * Panel self-update state (GET /admin/panel-update). Admin-only.
 * `available.checked: false` (release host unreachable) is a normal 200, not
 * a failure. Goes through `read()` so failures keep their status.
 */
export const getPanelUpdate = cache(async function getPanelUpdate() {
  const { data, failed, status, failure, message, debug } = await read(
    "/admin/panel-update",
    z.object({ panel_update: panelUpdateStateSchema }),
  );

  return { state: data?.panel_update ?? null, failed, status, failure, message, debug };
});
