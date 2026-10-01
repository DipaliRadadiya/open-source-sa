import { read } from "@/lib/api/read";
import { envHistoryResponseSchema } from "@/lib/schemas/environment";

/**
 * Who changed this application's `.env`, newest first. A failure is returned
 * as such, never as an empty history.
 */
export async function getEnvironmentHistory(id) {
  const result = await read(`/applications/${id}/environment/history`, envHistoryResponseSchema);

  // Pass the status and failure kind so the error box can tell them apart.
  return { history: result.failed ? null : (result.data?.history ?? null), meta: result.data?.meta ?? null, failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
}
