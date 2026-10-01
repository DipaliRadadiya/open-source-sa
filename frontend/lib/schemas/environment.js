import { z } from "zod";
import { listMetaSchema } from "./list.js";

// Only `raw` carries secret values; `variables` has secrets `null`.
export const envCheckSchema = z
  .object({
    code: z.string(),
    severity: z.enum(["warning", "error"]).catch("warning"),
    key: z.string().nullish(),
    value: z.string().nullish(),
    suggested: z.string().nullish(),
    title: z.string(),
    detail: z.string().nullish(),
  })
  .passthrough();

export const envBackupSchema = z
  .object({
    name: z.string(),
    created_at: z.string().nullish(),
  })
  .passthrough();

export const environmentSchema = z
  .object({
    exists: z.boolean().default(false),
    path: z.string().nullish(),
    framework: z.string().nullish(),
    framework_title: z.string().nullish(),
    requires_restart: z.boolean().default(false),
    requires_apply: z.boolean().default(false),
    raw: z.string().default(""),
    variables: z.array(z.object({}).passthrough()).default([]),
    checks: z.array(envCheckSchema).default([]),
    backups: z.array(envBackupSchema).default([]),
  })
  .passthrough();

export const environmentResponseSchema = z.object({
  environment: environmentSchema,
});

// Never add a value from the file here: those are secrets, and Zod's stripping is the safety net.
export const envHistoryEntrySchema = z
  .object({
    id: z.number(),
    action: z.string(),
    keys: z.string().nullish(),
    restored_from: z.string().nullish(),
    user: z
      .object({ id: z.number(), username: z.string() })
      .nullish(),
    is_system: z.boolean().default(false),
    created_at: z.string().nullish(),
    created_at_human: z.string().nullish(),
    backup: z.string().nullish(),
    // Required, not defaulted: a default of false would silently disable every Restore button.
    restorable: z.boolean(),
  })
  .passthrough();

export const envHistoryResponseSchema = z.object({
  history: z.array(envHistoryEntrySchema).default([]),
    // Twenty rows a page; needed to show that more exist.
  meta: listMetaSchema.nullish(),
});
