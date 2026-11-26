import { serverFetch } from "@/lib/api/server-fetch";
import { logReadResponseSchema } from "@/lib/schemas/log";
import { failedRead } from "@/lib/logs/failed-read";

export async function getLog(key, { lines = 200 } = {}) {
  try {
    const res = await serverFetch(`/logs/${encodeURIComponent(key)}`, {
      searchParams: { lines },
    });

    if (res.status === 403) return { status: "locked", log: null };
    if (res.status === 404) return { status: "missing", log: null };
    if (!res.ok) return failedRead(res);

    const parsed = logReadResponseSchema.safeParse(await res.json());
    return parsed.success
      ? { status: "ok", log: parsed.data.log }
      : { status: "failed", log: null };
  } catch {
    return { status: "failed", log: null };
  }
}
