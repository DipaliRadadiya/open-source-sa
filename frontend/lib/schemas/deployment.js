import { z } from "zod";

// GET /webhook-providers — the setup schema for each provider. `secret_source`
// decides the enable flow: "generate" (we mint it, user pastes into the
// provider) vs "either" (GitLab — paste its signing token, or use ours).
export const webhookProviderSchema = z
  .object({
    name: z.string(),
    title: z.string(),
    secret_source: z.enum(["generate", "either"]).catch("generate"),
    instructions: z.string().nullish(),
  })
  .passthrough();

export const webhookProvidersResponseSchema = z
  .object({
    webhook_providers: z.array(webhookProviderSchema).default([]),
  })
  .passthrough();
