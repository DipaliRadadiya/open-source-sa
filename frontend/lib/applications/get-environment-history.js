import { read } from "@/lib/api/read";
import { envHistoryResponseSchema } from "@/lib/schemas/environment";

export async function getEnvironmentHistory(id) {
  const result = await read(`/applications/${id}/environment/history`, envHistoryResponseSchema);

  return { history: result.failed ? null : (result.data?.history ?? null), meta: result.data?.meta ?? null, failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
}
