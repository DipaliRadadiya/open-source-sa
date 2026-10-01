import { z } from "zod";

// `secret_source`: "generate" (panel mints it) vs "either" (GitLab: paste its token, or use ours).
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
