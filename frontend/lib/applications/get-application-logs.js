import { serverFetch } from "@/lib/api/server-fetch";
import {
  applicationLogsResponseSchema,
  applicationLogResponseSchema,
} from "@/lib/schemas/application-log";

export async function getApplicationLogs(id) {
  try {
    const res = await serverFetch(`/applications/${id}/logs`);
    if (!res.ok) return { logs: [], failed: true };
    const parsed = applicationLogsResponseSchema.safeParse(await res.json());
    return parsed.success
      ? { logs: parsed.data.logs, failed: false }
      : { logs: [], failed: true };
  } catch {
    return { logs: [], failed: true };
  }
}

/**
 * First screen of one source, rendered server-side so the console paints with
 * content. 403/404 are states the UI explains, not failures.
 */
export async function getApplicationLog(id, key, { lines = 200 } = {}) {
  try {
    const res = await serverFetch(
      `/applications/${id}/logs/${encodeURIComponent(key)}`,
      {
        searchParams: { lines },
      },
    );
    if (res.status === 403) return { status: "locked", log: null };
    if (res.status === 404) return { status: "missing", log: null };
    if (!res.ok) return { status: "failed", log: null };
    const parsed = applicationLogResponseSchema.safeParse(await res.json());
    return parsed.success
      ? { status: "ok", log: parsed.data.log }
      : { status: "failed", log: null };
  } catch {
    return { status: "failed", log: null };
  }
}
