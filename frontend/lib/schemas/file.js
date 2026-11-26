import { z } from "zod";

const SAFE_PATH = /^(?!\/)(?!.*(^|\/)\.\.?(\/|$))[^\0]+$/;

export const fileEntrySchema = z.object({
  name: z.string(),
  type: z.enum(["dir", "file", "symlink"]),
  size: z.number().nullish(),
  size_human: z.string().nullish(),
  modified_at: z.string().nullish(),
  modified_at_human: z.string().nullish(),
  mode: z.string().nullish(),
  owner: z.string().nullish(),
  group: z.string().nullish(),
  link_target: z.string().nullish(),
  link_broken: z.boolean().nullish(),
});

export const breakdownSchema = z.object({
  available: z.boolean(),
  truncated: z.boolean().default(false),
  total_bytes: z.number().int().default(0),
  total_bytes_human: z.string().default(""),
  file_count: z.number().int().default(0),
  categories: z
    .array(
      z.object({
        key: z.string(),
        bytes: z.number().int(),
        bytes_human: z.string(),
        count: z.number().int(),
      }),
    )
    .default([]),
});

export const breakdownResponseSchema = z.object({
  breakdown: breakdownSchema,
});

const measuredSize = z.object({ size: z.number(), size_human: z.string() });

// PHP sends an empty sizes as [] instead of {}.
export const folderSizesResponseSchema = z.object({
  path: z.string().default(""),
  sizes: z.preprocess((v) => (Array.isArray(v) ? {} : v), z.record(z.string(), measuredSize)).default({}),
  total: measuredSize.nullish(),
  complete: z.boolean().default(false),
  measured_at: z.string().nullish(),
});

export const filesResponseSchema = z.object({
  path: z.string().default(""),
  files: z.array(fileEntrySchema).default([]),
  hidden_count: z.number().int(),
});

export const trashEntrySchema = z.object({
  batch: z.string(),
  path: z.string(),
  deleted_at: z.string().nullish(),
  size: z.number().nullish(),
  size_human: z.string().nullish(),
});

export const trashResponseSchema = z.object({
  trash: z.array(trashEntrySchema).default([]),
  total_size: z.number().nullish(),
  total_size_human: z.string().nullish(),
  retention_days: z.number().nullish(),
});

export const searchFileEntrySchema = fileEntrySchema.extend({
  path: z.string(),
});

export const searchResponseSchema = z.object({
  files: z.array(searchFileEntrySchema).default([]),
});

export const fileBackupSchema = z.object({
  name: z.string(),
  created_at: z.string().nullish(),
  created_at_human: z.string().nullish(),
});

export const fileContentSchema = z.object({
  path: z.string(),
  content: z.string(),
  size: z.number().nullish(),
  backups: z.array(fileBackupSchema).default([]),
  version: z.string().nullish(),
});

export const newFolderSchema = z.object({
  name: z
    .string()
    .trim()
    .min(1, "required_name")
    .max(255, "max255")
    .regex(SAFE_PATH, "invalidName")
    .refine((v) => !v.includes("/"), "noSlashes"),
});

export const newFileSchema = z.object({
  name: z
    .string()
    .trim()
    .min(1, "required_name")
    .max(255, "max255")
    .regex(SAFE_PATH, "invalidName")
    .refine((v) => !v.includes("/"), "noSlashes"),
});
