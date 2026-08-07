import { testDestination } from "@/lib/api/storage";
import { storageTestResponseSchema } from "@/lib/schemas/storage";

/**
 * Run the connection probe and return a plain verdict.
 *
 * The endpoint returns **200 whether or not the connection works** — the
 * request succeeded, and the outcome is `test.success` in the body. Every
 * caller was getting this wrong in the same way (treating "did not throw" as
 * "works", which reported a nonexistent bucket as healthy), so the unwrapping
 * lives here once.
 *
 * A transport-level failure — the panel itself unreachable — is a different
 * thing from a probe that ran and failed, but both mean "you cannot rely on
 * this destination", so both come back as `{ok: false}` with whatever we can
 * honestly say.
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
