import { serverFetch } from "@/lib/api/server-fetch";
import { logSourcesResponseSchema } from "@/lib/schemas/log";

/**
 * GET /api/logs — sources detected on this box.
 *
 * Returns `{ logs, failed }`. A failure must never render as "no logs on this
 * server" — that's a claim about the machine we didn't verify — but it also
 * shouldn't take the whole page down: the shell and the heading are still true.
 * The panel shows the failure where the list would have been.
 */
export async function getLogSources() {
  try {
    const res = await serverFetch("/logs");
    if (!res.ok) return { logs: [], failed: true };

    const parsed = logSourcesResponseSchema.safeParse(await res.json());
    return parsed.success
      ? { logs: parsed.data.logs, failed: false }
      : { logs: [], failed: true };
  } catch {
    return { logs: [], failed: true };
  }
}
