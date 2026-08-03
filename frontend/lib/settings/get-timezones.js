import { cache } from "react";
import { z } from "zod";
import { serverFetch } from "@/lib/api/server-fetch";

const timezonesResponseSchema = z.object({
  timezones: z
    .array(
      z.object({
        region: z.string(),
        zones: z
          .array(
            z.object({
              value: z.string(),
              label: z.string(),
              offset: z.string().nullable().optional(),
              offset_minutes: z.number().nullable().optional(),
            }),
          )
          .default([]),
      }),
    )
    .default([]),
});

/**
 * The timezone list, from the server.
 *
 * This replaces the browser's own list, which was wrong in two ways that both
 * bit: it had no `Etc/UTC` (so this server's real setting rendered as an empty
 * field) and it spells Kolkata `Asia/Calcutta` (which the API would reject).
 * The API's list is exactly what `PUT /settings/general` validates against.
 *
 * Not permission-gated — it's a reference list, and cron schedules will want it
 * too. Returns [] on failure; the field then falls back to showing just the
 * current value, which is still better than an empty dropdown.
 */
export const getTimezones = cache(async function getTimezones() {
  try {
    const res = await serverFetch("/timezones");
    if (!res.ok) return [];

    const parsed = timezonesResponseSchema.safeParse(await res.json());
    return parsed.success ? parsed.data.timezones : [];
  } catch {
    return [];
  }
});
