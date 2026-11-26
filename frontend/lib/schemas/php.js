import { z } from "zod";

export const lifecycleSchema = z.object({
  status: z.string(),
  eol_date: z.string().nullable().optional(),
  lts_name: z.string().nullable().optional(),
});

export const phpVersionSchema = z.object({
  version: z.string(),
  full_version: z.string().nullable().optional(),
  path: z.string().nullable().optional(),
  is_default: z.boolean().optional().default(false),
  status: z.string().nullable().optional(),
  reason: z.string().nullable().optional(),
  message: z.string().nullable().optional(),
  reference: z.string().nullable().optional(),
  started_at: z.string().nullable().optional(),
  started_at_human: z.string().nullable().optional(),
  queued: z.boolean().nullish(),
  current_step: z.string().nullable().optional(),
  output: z.string().nullable().optional(),
  source: z.string().nullable().optional(),
  in_use_by_panel: z.boolean().nullable().optional(),
  in_use_by: z.number().nullable().optional(),
  sites: z.array(z.string()).nullable().optional().default([]),
  sites_truncated: z.boolean().nullable().optional().default(false),
  lifecycle: lifecycleSchema.nullable().optional(),
  service: z.string().nullable().optional(),
  ini_path: z.string().nullable().optional(),
  missing_packages: z.array(z.string()).nullable().optional().default([]),
});

export const phpGroupSchema = z.object({
  manager: z.string().nullable().optional(),
  default: z.string().nullable().optional(),
  default_full_version: z.string().nullable().optional(),
  panel_version: z.string().nullable().optional(),
  lifecycle_available: z.boolean().nullable().optional().default(false),
  versions: z.array(phpVersionSchema).default([]),
  system: z
    .object({ version: z.string(), path: z.string().nullable().optional() })
    .nullable()
    .optional(),
  installable: z
    .array(
      z.union([
        z.string().transform((version) => ({ version, lifecycle: null })),
        z.object({ version: z.string(), lifecycle: lifecycleSchema.nullable().optional() }),
      ]),
    )
    .default([]),
});

export const phpExtensionSchema = z.object({
  name: z.string(),
  package: z.string().nullable().optional(),
  modules: z.array(z.string()).default([]),
  installed: z.boolean().optional().default(false),
  enabled: z.boolean().optional().default(false),
  builtin: z.boolean().optional().default(false),
  summary: z.string().nullish(),
  status: z.string().nullish(),
  queued: z.boolean().nullish(),
  current_step: z.string().nullish(),
  output: z.string().nullish(),
  reason: z.string().nullish(),
  message: z.string().nullish(),
  reference: z.string().nullish(),
  sapis: z
    .union([z.record(z.string(), z.boolean()), z.array(z.never())])
    .optional()
    .default({})
    .transform((value) => (Array.isArray(value) ? {} : value)),
});

export const ionCubeSchema = z.object({
  supported: z.boolean().default(false),
  installed: z.boolean().default(false),
  php_version: z.string().nullish(),
  loader_version: z.string().nullish(),
  sha256: z.string().nullish(),
  path: z.string().nullish(),
  source: z.string().nullish(),
  status: z.string().nullish(),
  reason: z.string().nullish(),
  message: z.string().nullish(),
  reference: z.string().nullish(),
});

export const ionCubeResponseSchema = z.object({ ioncube: ionCubeSchema });

export const phpExtensionsResponseSchema = z.object({
  extensions: z.array(phpExtensionSchema).default([]),
  panel_required: z.array(z.string()).default([]),
  toggle_supported: z.boolean().default(true),
});

export const phpIniResponseSchema = z.object({
  php_ini: z.object({
    version: z.string(),
    path: z.string(),
    contents: z.string(),
  }),
});

const iniNumber = z.union([z.number(), z.string()]).nullish();
export const phpCommonSettingsSchema = z.object({
  version: z.string(),
  settings: z.object({
    memory_limit: z.string().nullish(),
    upload_max_filesize: z.string().nullish(),
    post_max_size: z.string().nullish(),
    max_execution_time: iniNumber,
    max_input_time: iniNumber,
    max_input_vars: iniNumber,
  }),
});

const optionalInt = (min, max, range) =>
  z.union([z.literal("", { error: "integer" }), z.coerce.number({ error: "integer" }).int("integer").min(min, range).max(max, range)], { error: "integer" });
export const phpCommonSettingsFormSchema = z.object({
  memory_limit: z.union([z.literal(""), z.string().regex(/^(-1|\d+[KMG]?)$/i, "phpSize")]),
  upload_max_filesize: z.union([z.literal(""), z.string().regex(/^(-1|\d+[KMG]?)$/i, "phpSize")]),
  max_execution_time: optionalInt(0, 3600, "rangeSeconds3600"),
  max_input_time: optionalInt(-1, 3600, "rangeInputTime"),
  max_input_vars: optionalInt(100, 100000, "rangeInputVars"),
});
