import { read } from "@/lib/api/read";
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
  const result = await read("/logs", logSourcesResponseSchema);

  // WHICH failure, not just that there was one: without the status and the
  // kind, the error box on this screen printed the same sentence whether the
  // API refused, crashed, or was not there at all.
  return { logs: result.failed ? [] : (result.data?.logs ?? []), failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
}
