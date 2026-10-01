import { read } from "@/lib/api/read";
import { logSourcesResponseSchema } from "@/lib/schemas/log";

/**
 * GET /api/logs — sources detected on this box.
 *
 * Returns `{ logs, failed }`. A failure must never render as "no logs on this
 * server", nor take down the page; it is shown where the list would be.
 */
export async function getLogSources() {
  const result = await read("/logs", logSourcesResponseSchema);

  // Status and failure kind let the error box name the cause.
  return { logs: result.failed ? [] : (result.data?.logs ?? []), failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
}
