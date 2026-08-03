import { cache } from "react";
import { serverFetch } from "@/lib/api/server-fetch";
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
  try {
    const res = await serverFetch("/settings/reboot-schedule/presets");
    if (!res.ok) return { data: null, failed: true };

    const parsed = rebootSchedulePresetsSchema.safeParse(await res.json());
    return parsed.success ? { data: parsed.data, failed: false } : { data: null, failed: true };
  } catch {
    return { data: null, failed: true };
  }
});
