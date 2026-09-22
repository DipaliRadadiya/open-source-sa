import { cache } from "react";
import { read } from "@/lib/api/read";
import { rebootSchedulePresetsSchema } from "@/lib/schemas/settings";

/**
 * Options for the scheduled-restart dropdowns.
 *
 * A separate call because the labels are localized server-side — "Sunday" and
 * "Daily" are the API's words, not ours, so the two apps can never disagree
 * about what day 0 means.
 *
 * Failing here does not fail the page: the settings read still succeeds, and
 * the card says its options could not be loaded rather than showing empty
 * dropdowns that look broken.
 */
export const getRebootPresets = cache(async function getRebootPresets() {
  const result = await read("/settings/reboot-schedule/presets", rebootSchedulePresetsSchema);

  // Every field `read()` knows, not just whether it worked: without the
  // status and the kind, the failure box on this screen could not tell a
  // 403 from a 500 and printed the same unfalsifiable sentence for both.
  return {
    data: result.failed ? null : (result.data ?? null),
    failed: result.failed,
    status: result.status,
    failure: result.failure,
    message: result.message,
    debug: result.debug,
  };
});
