import { cache } from "react";
import { serverFetch } from "@/lib/api/server-fetch";
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
  try {
    const res = await serverFetch("/settings");
    if (!res.ok) {
      console.error("getSettings: /settings failed", res.status, await res.text());
      return { data: null, lastChanged: null, failed: true };
    }

    const parsed = settingsResponseSchema.safeParse(await res.json());
    if (!parsed.success) {
      console.error("getSettings: response failed schema validation", parsed.error);
      return { data: null, lastChanged: null, failed: true };
    }

    return {
      data: parsed.data.settings,
      lastChanged: parsed.data.last_changed ?? null,
      failed: false,
    };
  } catch (error) {
    console.error("getSettings: request threw", error);
    return { data: null, lastChanged: null, failed: true };
  }
});
