import { z } from "zod";
import { databaseInstallProgressSchema } from "./database.js";

const actionSchema = z
  .object({ method: z.string(), endpoint: z.string() })
  .nullable();

const optionSchema = z
  .object({
    value: z.string(),
    label: z.string(),
    installed: z.boolean().default(false),
    version: z.string().nullish(),
    installable: z.boolean().default(false),
    recommended: z.boolean().default(false),
    action: actionSchema.default(null),
  })
  .passthrough();

const componentSchema = z
  .object({
    key: z.string(),
    title: z.string(),
    description: z.string().nullish(),
    state: z.enum(["installed", "pending", "installing", "failed"]).catch("pending"),
    detail: z.string().nullish(),
    recommended: z.boolean().default(false),
    action: actionSchema.default(null),
    options: z.array(optionSchema).default([]),
    reason: z.string().nullish(),
    message: z.string().nullish(),
    retryable: z.boolean().default(false),
    progress: databaseInstallProgressSchema.nullish(),
  })
  .passthrough();

export const setupResponseSchema = z.object({
  setup: z
    .object({
      complete: z.boolean().default(false),
      status: z.string().default("idle"),
      percent: z.number().default(0),
      key: z.string().nullish(),
      label: z.string().nullish(),
      stack: z.string().nullish(),
      web_server: z.string().nullish(),
      system_updates_running: z.boolean().catch(false).default(false),
      components: z.array(componentSchema).default([]),
    })
    .passthrough(),
});
