import { serverFetch } from "@/lib/api/server-fetch";
import { webhookProvidersResponseSchema } from "@/lib/schemas/deployment";

/**
 * The connect-form schema for deploy-on-push. Fetched server-side so the
 * webhook card paints its provider list and setup steps on first render rather
 * than after a spinner. A failure is not fatal — the card still shows current
 * status; only the enable form needs the list.
 */
export async function getWebhookProviders() {
  try {
    const res = await serverFetch("/webhook-providers");
    if (!res.ok) return { providers: [], failed: true };
    const parsed = webhookProvidersResponseSchema.safeParse(await res.json());
    return parsed.success
      ? { providers: parsed.data.webhook_providers, failed: false }
      : { providers: [], failed: true };
  } catch {
    return { providers: [], failed: true };
  }
}
