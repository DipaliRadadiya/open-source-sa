import { serverFetch } from "@/lib/api/server-fetch";
import { logReadResponseSchema } from "@/lib/schemas/log";
import { failedRead } from "@/lib/logs/failed-read";

/**
 * GET /api/logs/{key} — first screen of a source, rendered server-side so the
 * viewer paints with content instead of a spinner.
 *
 * 403 (exists but unreadable by the panel) and 404 (gone since the catalog was
 * built) are expected states returned as a status for the UI to explain.
 * Anything else is `status: "failed"`, shown in place of the console only.
 */
export async function getLog(key, { lines = 200 } = {}) {
  try {
    const res = await serverFetch(`/logs/${encodeURIComponent(key)}`, {
      searchParams: { lines },
    });

    if (res.status === 403) return { status: "locked", log: null };
    if (res.status === 404) return { status: "missing", log: null };
    if (!res.ok) return failedRead(res);

    const parsed = logReadResponseSchema.safeParse(await res.json());
    // An unreadable shape is a failed read, not an empty log.
    return parsed.success
      ? { status: "ok", log: parsed.data.log }
      : { status: "failed", log: null };
  } catch {
    return { status: "failed", log: null };
  }
}
