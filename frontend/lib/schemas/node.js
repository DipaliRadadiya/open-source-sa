import { z } from "zod";
import { lifecycleSchema } from "@/lib/schemas/php";

// Like PHP, except every version reports its own npm and a `system` Node may exist that the panel never touches.
export const nodeVersionSchema = z.object({
  version: z.string(),
  path: z.string().nullable().optional(),
  is_default: z.boolean().optional().default(false),
  source: z.string().nullable().optional(),
  // Latest npm per the API; until sent, the card offers the update unconditionally.
  npm_latest: z.string().nullish(),
  // The API's semver comparison. False also means "no catalog", so only read
  // it when a latest is known.
  npm_update_available: z.boolean().nullish(),
  // Read from this version's own npm; null when unreadable (show nothing).
  npm_version: z.string().nullable().optional(),
  // ready | installing | removing | failed. Only ready versions can be selected
  // by an application.
  status: z.string().nullable().optional(),
  // Install progress and failure details, same as the PHP schema.
  reason: z.string().nullable().optional(),
  message: z.string().nullable().optional(),
  reference: z.string().nullable().optional(),
  started_at: z.string().nullable().optional(),
  started_at_human: z.string().nullable().optional(),
  current_step: z.string().nullable().optional(),
  // fnm's own output: the only thing that says why an install stopped.
  output: z.string().nullable().optional(),
  // How many sites pin this version, and up to five of them by name.
  in_use_by: z.number().nullable().optional(),
  sites: z.array(z.string()).nullable().optional().default([]),
  sites_truncated: z.boolean().nullable().optional().default(false),
  lifecycle: lifecycleSchema.nullable().optional(),
});

export const nodeGroupSchema = z.object({
  // fnm | system | none. "none" is a normal state, not an error.
  manager: z.string().nullable().optional(),
  default: z.string().nullable().optional(),
  lifecycle_available: z.boolean().nullable().optional().default(false),
  versions: z.array(nodeVersionSchema).default([]),
  // A Node already on the machine: usable, never modified, so no controls.
  system: z
    .object({ version: z.string(), path: z.string().nullable().optional() })
    .nullable()
    .optional(),
  // Same tolerance as PHP: a bare string or an object, so version skew cannot
  // fail the whole response.
  installable: z
    .array(
      z.union([
        z.string().transform((version) => ({ version, lifecycle: null })),
        z.object({ version: z.string(), lifecycle: lifecycleSchema.nullable().optional() }),
      ]),
    )
    .default([]),
});
