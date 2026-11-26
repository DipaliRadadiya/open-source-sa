import { z } from "zod";
import { lifecycleSchema } from "@/lib/schemas/php";

export const nodeVersionSchema = z.object({
  version: z.string(),
  path: z.string().nullable().optional(),
  is_default: z.boolean().optional().default(false),
  source: z.string().nullable().optional(),
  npm_latest: z.string().nullish(),
  npm_update_available: z.boolean().nullish(),
  npm_version: z.string().nullable().optional(),
  status: z.string().nullable().optional(),
  reason: z.string().nullable().optional(),
  message: z.string().nullable().optional(),
  reference: z.string().nullable().optional(),
  started_at: z.string().nullable().optional(),
  started_at_human: z.string().nullable().optional(),
  queued: z.boolean().nullish(),
  current_step: z.string().nullable().optional(),
  output: z.string().nullable().optional(),
  in_use_by: z.number().nullable().optional(),
  sites: z.array(z.string()).nullable().optional().default([]),
  sites_truncated: z.boolean().nullable().optional().default(false),
  lifecycle: lifecycleSchema.nullable().optional(),
});

export const nodeGroupSchema = z.object({
  manager: z.string().nullable().optional(),
  default: z.string().nullable().optional(),
  lifecycle_available: z.boolean().nullable().optional().default(false),
  versions: z.array(nodeVersionSchema).default([]),
  system: z
    .object({ version: z.string(), path: z.string().nullable().optional() })
    .nullable()
    .optional(),
  installable_error: z.string().nullish(),
  installable: z
    .array(
      z.union([
        z.string().transform((version) => ({ version, lifecycle: null })),
        z.object({ version: z.string(), lifecycle: lifecycleSchema.nullable().optional() }),
      ]),
    )
    .default([]),
});

export const buildToolsResponseSchema = z.object({
  build_tools: z.object({
    installed: z.boolean(),
    missing: z.array(z.string()).default([]),
    install_status: z.string().nullish(),
    install_message: z.string().nullish(),
  }),
});
