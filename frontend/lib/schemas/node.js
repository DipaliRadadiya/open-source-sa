import { z } from "zod";
import { lifecycleSchema } from "@/lib/schemas/php";

/**
 * Node.js versions.
 *
 * Deliberately the same shape as PHP — the API was built that way — with two
 * differences: every version reports its own npm, and the box may carry a
 * `system` Node that the panel did not install and never touches.
 */
export const nodeVersionSchema = z.object({
  version: z.string(),
  path: z.string().nullable().optional(),
  is_default: z.boolean().optional().default(false),
  source: z.string().nullable().optional(),
  // Read from THIS version's own npm. Null when it couldn't be read — show
  // nothing rather than the default version's number next to every row.
  npm_version: z.string().nullable().optional(),
  // ready | installing | removing | failed. Only ready versions can be selected
  // by an application.
  status: z.string().nullable().optional(),
  // Everything below was missing, so Zod stripped it before the card ever saw
  // it: the screen could say "Installing" but never for how long, and "did not
  // install" but never why. The PHP schema has carried these all along —
  // an install that fails on Node looked identical to one that failed silently.
  reason: z.string().nullable().optional(),
  message: z.string().nullable().optional(),
  reference: z.string().nullable().optional(),
  started_at: z.string().nullable().optional(),
  started_at_human: z.string().nullable().optional(),
  current_step: z.string().nullable().optional(),
  // How many sites pin this version, and up to five by name — "3 sites" doesn't
  // tell you whether removing it breaks staging or the shop.
  in_use_by: z.number().nullable().optional(),
  sites: z.array(z.string()).nullable().optional().default([]),
  sites_truncated: z.boolean().nullable().optional().default(false),
  lifecycle: lifecycleSchema.nullable().optional(),
});

export const nodeGroupSchema = z.object({
  // fnm | system | none. "none" is a normal state on a fresh box, not an error.
  manager: z.string().nullable().optional(),
  default: z.string().nullable().optional(),
  lifecycle_available: z.boolean().nullable().optional().default(false),
  versions: z.array(nodeVersionSchema).default([]),
  // A Node that was already on the machine. Reported so it can be used, never
  // modified — so it gets no controls.
  system: z
    .object({ version: z.string(), path: z.string().nullable().optional() })
    .nullable()
    .optional(),
  // Same tolerance as PHP: accept a bare string or an object, so a version skew
  // between the two apps can't cost the whole response.
  installable: z
    .array(
      z.union([
        z.string().transform((version) => ({ version, lifecycle: null })),
        z.object({ version: z.string(), lifecycle: lifecycleSchema.nullable().optional() }),
      ]),
    )
    .default([]),
});
