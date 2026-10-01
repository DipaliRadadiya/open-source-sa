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

// Exactly what `PUT /settings/general` validates against (the browser's list
// differs, e.g. no `Etc/UTC`). [] on failure.
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
