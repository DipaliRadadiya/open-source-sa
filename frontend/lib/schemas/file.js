import { z } from "zod";

// Same rule the backend applies (App\Rules\SafeRelativePath): relative only, no
// leading slash, no `.`/`..` segments. Client-side this only has to catch
// obvious mistakes before a round-trip — the backend is the real boundary,
// since every file operation runs as the site's own Linux user regardless.
const SAFE_PATH = /^(?!\/)(?!.*(^|\/)\.\.?(\/|$))[^\0]+$/;

export const fileEntrySchema = z.object({
  name: z.string(),
  type: z.enum(["dir", "file", "symlink"]),
  size: z.number().nullish(),
  size_human: z.string().nullish(),
  modified_at: z.string().nullish(),
  modified_at_human: z.string().nullish(),
  // Newer, optional fields — nullish so a listing from before these existed
  // (or a backend that hasn't shipped them yet) parses exactly as it always
  // has, and the UI falls back to today's behavior wherever one is absent.
  mode: z.string().nullish(),
  owner: z.string().nullish(),
  group: z.string().nullish(),
});

export const filesResponseSchema = z.object({
  path: z.string().default(""),
  files: z.array(fileEntrySchema).default([]),
});

// One entry per deleted path, newest first. `batch` is the timestamped folder
// one delete produced (`YYYYMMDD-HHMMSS`), shared by everything removed in that
// action; `path` is where it came from, which is also where it goes back to.
// Size and retention ARE reported — the backend added both after this shape was
// first written, and the stale note here saying otherwise meant Zod silently
// stripped them for as long as it stood. `size` is a real `du` footprint (so a
// directory's figure is meaningful, not its inode size), and it is nullish
// because one unmeasurable entry must not blank the rest.
export const trashEntrySchema = z.object({
  batch: z.string(),
  path: z.string(),
  deleted_at: z.string().nullish(),
  size: z.number().nullish(),
  size_human: z.string().nullish(),
});

export const trashResponseSchema = z.object({
  trash: z.array(trashEntrySchema).default([]),
  // Null when any single entry could not be measured: a total that quietly
  // omits one directory is worse than no total, because it reads as complete.
  total_size: z.number().nullish(),
  total_size_human: z.string().nullish(),
  // Configurable per install (SERVER_TRASH_RETENTION_DAYS), so it is read from
  // the response and never hardcoded — a screen promising "7 days" on a server
  // set to 30 is worse than one that says nothing.
  retention_days: z.number().nullish(),
});

// Sitewide search results span multiple folders, so each entry carries its
// own full relative `path` — everything else is the same shape the listing
// endpoint already returns.
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

// Every write op below mirrors the request body the API actually takes — no
// field invents anything it doesn't send.

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

// Rename and move are the same endpoint (`target` must not already exist) —
// the dialog just decides how much of the pre-filled path it selects.
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
    // zip and tar.gz both, because the API writes both — and tar is the one
    // that keeps Unix modes, so it is the right pick for "copy this before I
    // touch it". `.tgz` is accepted as the alias it is.
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
