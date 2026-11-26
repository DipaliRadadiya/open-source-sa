import { read } from "@/lib/api/read";
import { environmentResponseSchema } from "@/lib/schemas/environment";

export async function getApplicationEnvironment(id) {
  const result = await read(`/applications/${id}/environment`, environmentResponseSchema);

  return { environment: result.failed ? null : (result.data?.environment ?? null), failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
}
