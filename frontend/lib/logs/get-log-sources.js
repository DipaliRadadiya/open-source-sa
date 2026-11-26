import { read } from "@/lib/api/read";
import { logSourcesResponseSchema } from "@/lib/schemas/log";

export async function getLogSources() {
  const result = await read("/logs", logSourcesResponseSchema);

  return { logs: result.failed ? [] : (result.data?.logs ?? []), failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
}
