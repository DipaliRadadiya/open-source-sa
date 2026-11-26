import { read } from "@/lib/api/read";
import { webhookProvidersResponseSchema } from "@/lib/schemas/deployment";

export async function getWebhookProviders() {
  const result = await read("/webhook-providers", webhookProvidersResponseSchema);

  return { providers: result.failed ? [] : (result.data?.webhook_providers ?? []), failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
}
