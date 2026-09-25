import { serverFetch } from "@/lib/api/server-fetch";
import { logReadResponseSchema } from "@/lib/schemas/log";
import { failedRead } from "@/lib/logs/failed-read";

/**
 * GET /api/logs/{key} — first screen of a source, rendered server-side so the
 * viewer paints with content instead of a spinner.
 *
 * 403 (exists but unreadable by the panel) and 404 (gone since the catalog was
 * built) are expected states the UI explains, not errors — they come back as a
 * status the caller renders. Anything else is `status: "failed"`, which the
 * viewer shows in place of the console: one unreadable file is no reason to
 * take away the source list and the rest of the page.
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
    // A shape this page can't read is a failed read. As "ok" with no log it
    // said "This log is empty" over a log with lines in it, until the browser
    // read it again seconds later.
    return parsed.success
      ? { status: "ok", log: parsed.data.log }
      : { status: "failed", log: null };
  } catch {
    return { status: "failed", log: null };
  }
}
