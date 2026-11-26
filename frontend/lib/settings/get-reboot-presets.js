import { cache } from "react";
import { read } from "@/lib/api/read";
import { rebootSchedulePresetsSchema } from "@/lib/schemas/settings";

export const getRebootPresets = cache(async function getRebootPresets() {
  const result = await read("/settings/reboot-schedule/presets", rebootSchedulePresetsSchema);

  return {
    data: result.failed ? null : (result.data ?? null),
    failed: result.failed,
    status: result.status,
    failure: result.failure,
    message: result.message,
    debug: result.debug,
  };
});
