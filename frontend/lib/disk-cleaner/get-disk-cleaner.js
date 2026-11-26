import { cache } from "react";
import { read } from "@/lib/api/read";
import { serverFetch } from "@/lib/api/server-fetch";
import {
  cleanerPreviewSchema,
  cleanerScheduleSchema,
  cleanerRunsSchema,
} from "@/lib/schemas/disk-cleaner";

export const getDiskCleaner = cache(async function getDiskCleaner() {
  const result = await read("/disk-cleaner", cleanerPreviewSchema);

  return {
    data: result.failed ? null : (result.data ?? null),
    failed: result.failed,
    status: result.status,
    failure: result.failure,
    message: result.message,
    debug: result.debug,
  };
});

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

export const getCleanerRuns = cache(async function getCleanerRuns(page = 1, perPage = 10) {
  try {
    const res = await serverFetch("/disk-cleaner/runs", { searchParams: { page, per_page: perPage } });
    if (!res.ok) return { runs: [] };

    const parsed = cleanerRunsSchema.safeParse(await res.json());
    return parsed.success ? parsed.data : { runs: [] };
  } catch {
    return { runs: [] };
  }
});
