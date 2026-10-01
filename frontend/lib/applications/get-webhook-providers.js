import { read } from "@/lib/api/read";
import { webhookProvidersResponseSchema } from "@/lib/schemas/deployment";

/**
 * The connect-form schema for deploy-on-push. A failure is not fatal: only the
 * enable form needs the list.
 */
export async function getWebhookProviders() {
  const result = await read("/webhook-providers", webhookProvidersResponseSchema);

  // Pass the status and failure kind so the error box can tell them apart.
  return { providers: result.failed ? [] : (result.data?.webhook_providers ?? []), failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
}
