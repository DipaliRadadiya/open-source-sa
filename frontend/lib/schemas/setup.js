import { z } from "zod";
import { databaseInstallProgressSchema } from "./database.js";

// The endpoint to POST to install a component (or an option). `null` means the
// current server configuration cannot install it — render no button.
const actionSchema = z
  .object({ method: z.string(), endpoint: z.string() })
  .nullable();

// A database engine choice (only the database component carries these).
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
    // Detected live, never remembered.
    state: z.enum(["installed", "pending", "installing", "failed"]).catch("pending"),
    detail: z.string().nullish(),
    recommended: z.boolean().default(false),
    action: actionSchema.default(null),
    options: z.array(optionSchema).default([]),
    // reason/message can be present even when state is not "failed" (a stale
    // prior attempt) — only ever shown when state === "failed".
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
      components: z.array(componentSchema).default([]),
    })
    .passthrough(),
});
