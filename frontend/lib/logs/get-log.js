import { serverFetch } from "@/lib/api/server-fetch";
import { logReadResponseSchema } from "@/lib/schemas/log";

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
    if (!res.ok) return { status: "failed", log: null };

    const parsed = logReadResponseSchema.safeParse(await res.json());
    return parsed.success
      ? { status: "ok", log: parsed.data.log }
      : { status: "ok", log: null };
  } catch {
    return { status: "failed", log: null };
  }
}
