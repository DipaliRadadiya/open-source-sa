import { z } from "zod";

// Same rule as App\Rules\SafeRelativePath: relative only, no `.`/`..`
// segments. Catches obvious mistakes; the backend is the real boundary.
const SAFE_PATH = /^(?!\/)(?!.*(^|\/)\.\.?(\/|$))[^\0]+$/;

export const fileEntrySchema = z.object({
  name: z.string(),
  type: z.enum(["dir", "file", "symlink"]),
  size: z.number().nullish(),
  size_human: z.string().nullish(),
  modified_at: z.string().nullish(),
  modified_at_human: z.string().nullish(),
  // Nullish so older listings without these fields still parse.
  mode: z.string().nullish(),
  owner: z.string().nullish(),
  group: z.string().nullish(),
  // Symlinks only: where a link points and whether it dangles.
  link_target: z.string().nullish(),
  link_broken: z.boolean().nullish(),
});

// `available: false` (the walk could not finish) differs from empty `categories`.
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

// `GET …/files/sizes`. PHP sends an empty `sizes` as `[]`, not `{}`. A folder missing
// from `sizes` was not measured; `total` is null when `complete` is false.
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
  // How many dotfiles this directory holds, whether or not they were returned.
  // Required so a missing field fails loudly instead of reading as "none hidden".
  hidden_count: z.number().int(),
});

// `batch` is the delete's folder (`YYYYMMDD-HHMMSS`). `size` is nullish so one
// unmeasurable entry does not blank the rest.
export const trashEntrySchema = z.object({
  batch: z.string(),
  path: z.string(),
  deleted_at: z.string().nullish(),
  size: z.number().nullish(),
  size_human: z.string().nullish(),
});

export const trashResponseSchema = z.object({
  trash: z.array(trashEntrySchema).default([]),
  // Null when any entry could not be measured; a partial total reads as complete.
  total_size: z.number().nullish(),
  total_size_human: z.string().nullish(),
  // Configurable per install (SERVER_TRASH_RETENTION_DAYS); never hardcode it.
  retention_days: z.number().nullish(),
});

// Sitewide search spans folders, so each entry carries its full relative `path`.
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
});

// Write schemas below mirror the request bodies the API takes.

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

// Rename and move are the same endpoint (`target` must not already exist).
export const renameSchema = z.object({
  target: z.string().trim().min(1, "required_target").regex(SAFE_PATH, "invalidPath"),
});

export const copySchema = z.object({
  target: z.string().trim().min(1, "required_target").regex(SAFE_PATH, "invalidPath"),
});

export const compressSchema = z.object({
  target: z
    .string()
    .trim()
    .min(1, "required_target")
    .regex(SAFE_PATH, "invalidPath")
    // zip and tar.gz (tar keeps Unix modes); `.tgz` is accepted as an alias.
    .refine((v) => /\.(zip|tar\.gz|tgz)$/i.test(v), "mustBeArchive"),
});

export const extractSchema = z.object({
  target: z.string().trim().min(1, "required_target").regex(SAFE_PATH, "invalidPath"),
});

export const PERMISSION_PRESETS = ["644", "755", "600"];

export const permissionsSchema = z.object({
  mode: z
    .string()
    .trim()
    .regex(/^[0-7]?[0-7]{3}$/, "invalidMode"),
});
