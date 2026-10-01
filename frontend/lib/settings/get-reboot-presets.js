import { cache } from "react";
import { read } from "@/lib/api/read";
import { rebootSchedulePresetsSchema } from "@/lib/schemas/settings";

// A failure does not fail the page; the card reports it.
export const getRebootPresets = cache(async function getRebootPresets() {
  const result = await read("/settings/reboot-schedule/presets", rebootSchedulePresetsSchema);

  // Pass every read() field so the failure box can tell a 403 from a 500.
  return {
    data: result.failed ? null : (result.data ?? null),
    failed: result.failed,
    status: result.status,
    failure: result.failure,
    message: result.message,
    debug: result.debug,
  };
});
