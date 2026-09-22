import { read } from "@/lib/api/read";
import { workersResponseSchema } from "@/lib/schemas/worker";

const EMPTY = { workers: [], presets: [], checks: [] };

// WHICH failure, not just that there was one: a shared FAILED constant made
// every outcome identical, so the error box could not tell a refusal from a
// crash and printed the same unfalsifiable sentence for both.
export async function getWorkers(appId) {
  const result = await read(`/applications/${appId}/workers`, workersResponseSchema);

  if (result.failed) return { ...EMPTY, failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };

  return { ...result.data, failed: false, status: result.status, failure: null, message: null, debug: false };
}
