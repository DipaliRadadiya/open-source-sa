import { read } from "@/lib/api/read";
import { setupResponseSchema } from "@/lib/schemas/setup";

/**
 * The install checklist (GET /setup): what is recommended, installed and missing.
 * Uses `read()` so a 403, 500, dead request and shape mismatch stay distinct and
 * reach the journal.
 */
export async function getSetup() {
  const { data, failed, status, failure, message, debug } = await read("/setup", setupResponseSchema);

  return { setup: data?.setup ?? null, failed, status, failure, message, debug };
}
