import { z } from "zod";

/**
 * A site's `.env`, returned in three shapes at once: `raw` (the file text — the
 * only field that carries secret values, so it is what the editor shows),
 * parsed `variables` (value `null` for secrets), and `checks` that judge the
 * file. The two `requires_*` flags say why a save might look like it did
 * nothing, so the Save button can name what it will actually do.
 */
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
