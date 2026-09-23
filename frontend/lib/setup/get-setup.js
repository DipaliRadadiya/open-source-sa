import { read } from "@/lib/api/read";
import { setupResponseSchema } from "@/lib/schemas/setup";

/**
 * The install checklist (GET /setup) — what is recommended, what is already
 * installed, and what is still missing.
 *
 * Through `read()` rather than its own try/catch: it collapsed a 403, a 500, a
 * dead request and a shape mismatch into one `failed: true`, so the setup
 * screen could say nothing beyond "could not be loaded" and nothing reached
 * the journal. This is the first screen a new install sees — the screen least
 * able to afford an unexplained error.
 */
export async function getSetup() {
  const { data, failed, status, failure, message, debug } = await read("/setup", setupResponseSchema);

  return { setup: data?.setup ?? null, failed, status, failure, message, debug };
}
