import { serverFetch } from "@/lib/api/server-fetch";
import { setupResponseSchema } from "@/lib/schemas/setup";

/**
 * The setup state — one read that drives the first-run checklist. Returns the
 * parsed `setup` object, or `{ failed: true }` so the page can show a retry
 * rather than a blank screen.
 */
export async function getSetup() {
  try {
    const res = await serverFetch("/setup");
    if (!res.ok) return { setup: null, failed: true };
    const parsed = setupResponseSchema.safeParse(await res.json());
    return parsed.success
      ? { setup: parsed.data.setup, failed: false }
      : { setup: null, failed: true };
  } catch {
    return { setup: null, failed: true };
  }
}
