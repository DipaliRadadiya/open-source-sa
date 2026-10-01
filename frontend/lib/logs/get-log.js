import { serverFetch } from "@/lib/api/server-fetch";
import { logReadResponseSchema } from "@/lib/schemas/log";
import { failedRead } from "@/lib/logs/failed-read";

// 403 (unreadable) and 404 (gone) are expected states for the UI to explain;
// anything else is `status: "failed"`.
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
