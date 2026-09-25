import { read } from "@/lib/api/read";
import { envHistoryResponseSchema } from "@/lib/schemas/environment";

/**
 * Who changed this application's `.env`, newest first.
 *
 * Returns the failure rather than an empty list, because they are not the same
 * sentence: "nobody has changed this file" and "the panel could not read the
 * record of who changed this file" would look identical on screen, and only one
 * of them should reassure anyone.
 */
export async function getEnvironmentHistory(id) {
  const result = await read(`/applications/${id}/environment/history`, envHistoryResponseSchema);

  // WHICH failure, not just that there was one: without the status and the
  // kind, the error box on this screen printed the same sentence whether the
  // API refused, crashed, or was not there at all.
  return { history: result.failed ? null : (result.data?.history ?? null), meta: result.data?.meta ?? null, failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
}
