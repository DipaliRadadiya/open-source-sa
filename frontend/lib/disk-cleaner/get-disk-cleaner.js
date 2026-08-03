import { cache } from "react";
import { serverFetch } from "@/lib/api/server-fetch";
import {
  cleanerPreviewSchema,
  cleanerScheduleSchema,
  cleanerRunsSchema,
} from "@/lib/schemas/disk-cleaner";

// Schemas are imported, never restated here: a second copy of the shape drifts
// the moment the API changes and the page reads "we couldn't load this" with
// nothing actually wrong.

/** Live disk usage + what each category could reclaim. Read fresh every time. */
export const getDiskCleaner = cache(async function getDiskCleaner() {
  try {
    const res = await serverFetch("/disk-cleaner");
    if (!res.ok) return { data: null, failed: true };

    const parsed = cleanerPreviewSchema.safeParse(await res.json());
    return parsed.success ? { data: parsed.data, failed: false } : { data: null, failed: true };
  } catch {
    return { data: null, failed: true };
  }
});

/**
 * The automatic-cleanup profile. A missing schedule is not a failure — the API
 * returns defaults — so this degrades to `null` and the card renders its "off"
 * state rather than an error.
 */
export const getCleanerSchedule = cache(async function getCleanerSchedule() {
  try {
    const res = await serverFetch("/disk-cleaner/schedule");
    if (!res.ok) return null;

    const json = await res.json();
    const parsed = cleanerScheduleSchema.safeParse(json.schedule ?? json);
    return parsed.success ? parsed.data : null;
  } catch {
    return null;
  }
});

/** Recent runs, manual and scheduled. Empty is the normal first-run state. */
export const getCleanerRuns = cache(async function getCleanerRuns() {
  try {
    const res = await serverFetch("/disk-cleaner/runs", { searchParams: { per_page: 10 } });
    if (!res.ok) return { runs: [] };

    const parsed = cleanerRunsSchema.safeParse(await res.json());
    return parsed.success ? parsed.data : { runs: [] };
  } catch {
    return { runs: [] };
  }
});
