import { cache } from "react";
import { read } from "@/lib/api/read";
import { settingsResponseSchema } from "@/lib/schemas/settings";

/**
 * GET /api/settings: every available group in one call, cached per request
 * (read by both the settings layout and the open section).
 *
 * A group missing from `data` means the server lacks it (e.g. no Redis), not a
 * failed read. `lastChanged` is keyed by group name.
 */
export const getSettings = cache(async function getSettings() {
  const result = await read("/settings", settingsResponseSchema);

  // Pass on which failure and the API's message; read() already logs it.
  if (result.failed) {
    return {
      data: null,
      lastChanged: null,
      failed: true,
      status: result.status,
      failure: result.failure,
      message: result.message,
      debug: result.debug,
    };
  }

  return {
    data: result.data.settings,
    lastChanged: result.data.last_changed ?? null,
    failed: false,
    status: result.status,
    failure: null,
    message: null,
    debug: false,
  };
});

