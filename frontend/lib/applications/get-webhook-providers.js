import { read } from "@/lib/api/read";
import { webhookProvidersResponseSchema } from "@/lib/schemas/deployment";

/**
 * The connect-form schema for deploy-on-push. Fetched server-side so the
 * webhook card paints its provider list and setup steps on first render rather
 * than after a spinner. A failure is not fatal — the card still shows current
 * status; only the enable form needs the list.
 */
export async function getWebhookProviders() {
  const result = await read("/webhook-providers", webhookProvidersResponseSchema);

  // WHICH failure, not just that there was one: without the status and the
  // kind, the error box on this screen printed the same sentence whether the
  // API refused, crashed, or was not there at all.
  return { providers: result.failed ? [] : (result.data?.providers ?? []), failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
}
