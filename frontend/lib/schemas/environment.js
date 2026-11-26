import { z } from "zod";
import { listMetaSchema } from "./list.js";

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

// Don't add values from the file here, they are secrets.
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
    restorable: z.boolean(),
    safety_copy: z.boolean().nullish(),
  })
  .passthrough();

export const envHistoryResponseSchema = z.object({
  history: z.array(envHistoryEntrySchema).default([]),
  meta: listMetaSchema.nullish(),
});
