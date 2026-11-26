import { z } from "zod";

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
