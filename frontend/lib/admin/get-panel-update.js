import { cache } from "react";
import { z } from "zod";
import { read } from "@/lib/api/read";
import { panelUpdateStateSchema } from "@/lib/schemas/panel-update";

export const getPanelUpdate = cache(async function getPanelUpdate() {
  const { data, failed, status, failure, message, debug } = await read(
    "/admin/panel-update",
    z.object({ panel_update: panelUpdateStateSchema }),
  );

  return { state: data?.panel_update ?? null, failed, status, failure, message, debug };
});
