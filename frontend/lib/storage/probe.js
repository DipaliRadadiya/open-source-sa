import { testDestination } from "@/lib/api/storage";
import { storageTestResponseSchema } from "@/lib/schemas/storage";

/**
 * Run the connection probe and return a plain verdict.
 *
 * The endpoint returns 200 whether or not the connection works; the outcome
 * is `test.success` in the body, so "did not throw" does not mean "works".
 * A transport failure also returns `{ok: false}`: either way the destination
 * cannot be relied on.
 */
export async function probeDestination(id, fallbackMessage) {
  try {
    const { data } = await testDestination(id);
    const parsed = storageTestResponseSchema.safeParse(data);
    if (!parsed.success) return { ok: false, message: fallbackMessage };

    const { success, message, latency_ms: latency } = parsed.data.test;
    return { ok: success, message: message || fallbackMessage, latency };
  } catch {
    return { ok: false, message: fallbackMessage };
  }
}
