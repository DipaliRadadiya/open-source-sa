import { testDestination } from "@/lib/api/storage";
import { storageTestResponseSchema } from "@/lib/schemas/storage";

// The endpoint returns 200 either way; the outcome is `test.success`. A transport failure is also `{ok: false}`.
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
