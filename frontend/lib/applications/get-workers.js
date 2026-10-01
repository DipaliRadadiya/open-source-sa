import { read } from "@/lib/api/read";
import { workersResponseSchema } from "@/lib/schemas/worker";

const EMPTY = { workers: [], presets: [], checks: [] };

// Returns the failure details so the error box can tell a refusal from a crash.
export async function getWorkers(appId) {
  const result = await read(`/applications/${appId}/workers`, workersResponseSchema);

  if (result.failed) return { ...EMPTY, failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };

  return { ...result.data, failed: false, status: result.status, failure: null, message: null, debug: false };
}
