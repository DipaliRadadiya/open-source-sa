import { read } from "@/lib/api/read";
import { environmentResponseSchema } from "@/lib/schemas/environment";

export async function getApplicationEnvironment(id) {
  const result = await read(`/applications/${id}/environment`, environmentResponseSchema);

  // WHICH failure, not just that there was one: without the status and the
  // kind, the error box on this screen printed the same sentence whether the
  // API refused, crashed, or was not there at all.
  return { environment: result.failed ? null : (result.data?.environment ?? null), failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
}
