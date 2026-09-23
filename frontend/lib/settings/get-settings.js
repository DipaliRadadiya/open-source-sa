import { cache } from "react";
import { read } from "@/lib/api/read";
import { settingsResponseSchema } from "@/lib/schemas/settings";

/**
 * GET /api/settings — every available group in one call.
 *
 * `cache()`d for the request, because the settings layout reads it for the tab
 * warning badges and the open section reads it again for its own values; that
 * must not be two round-trips, and the two must never disagree.
 *
 * Returns `{ data, lastChanged, failed }`. A group missing from `data` means
 * the server doesn't have it (e.g. no Redis installed) — not that the read went
 * wrong. `lastChanged` is keyed by the same group names.
 */
export const getSettings = cache(async function getSettings() {
  const result = await read("/settings", settingsResponseSchema);

  // WHICH failure, not just that there was one. `read()` also reports each one
  // centrally, which is what the three console.error calls here were doing by
  // hand — and it carries the API's own sentence, which they discarded after
  // printing it to a log nobody reading the screen can see.
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

