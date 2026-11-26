import { read } from "@/lib/api/read";
import { workersResponseSchema } from "@/lib/schemas/worker";

const EMPTY = { workers: [], presets: [], checks: [] };

export async function getWorkers(appId) {
  const result = await read(`/applications/${appId}/workers`, workersResponseSchema);

  if (result.failed) return { ...EMPTY, failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };

  return { ...result.data, failed: false, status: result.status, failure: null, message: null, debug: false };
}
