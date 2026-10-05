import { apiMessage } from "@/lib/api/error-message";
import { testDestination } from "@/lib/api/storage";
import { storageTestResponseSchema } from "@/lib/schemas/storage";

// The endpoint returns 200 either way; the outcome is `test.success`. A failed request
// is `{ok: false, notRun: true}`: the test never ran, so it says nothing about the destination.
export async function probeDestination(id, fallbackMessage, notRunMessage = fallbackMessage) {
  try {
    const { data } = await testDestination(id);
    const parsed = storageTestResponseSchema.safeParse(data);
    if (!parsed.success) return { ok: false, message: fallbackMessage };

    const { success, message, latency_ms: latency } = parsed.data.test;
    return { ok: success, message: message || fallbackMessage, latency };
  } catch (error) {
    return { ok: false, notRun: true, message: apiMessage(error, notRunMessage) };
  }
}
