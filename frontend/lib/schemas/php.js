import { z } from "zod";

/**
 * PHP versions and extensions.
 *
 * The API gives Node the same shape on purpose, so most of this is reusable —
 * the differences are in the data (PHP has extensions and an ini; Node has an
 * unmanaged "system" install PHP never has).
 */

/**
 * Upstream support state. `lts_name` is Node-only — PHP has no LTS releases,
 * so the field is absent here rather than present and always null.
 */
export const lifecycleSchema = z.object({
  status: z.string(),
  eol_date: z.string().nullable().optional(),
  lts_name: z.string().nullable().optional(),
});

export const phpVersionSchema = z.object({
  version: z.string(),
  path: z.string().nullable().optional(),
  is_default: z.boolean().optional().default(false),
  // ready | installing | failed. Anything but ready means there is no PHP on
  // disk for this version, so its extensions and php.ini 404 — the page has to
  // say so rather than render an empty space.
  status: z.string().nullable().optional(),
  // Why it failed, in the API's own words, plus the id support will ask for.
  // Inventing our own copy here would be a guess about someone else's failure.
  reason: z.string().nullable().optional(),
  message: z.string().nullable().optional(),
  reference: z.string().nullable().optional(),
  // When the install began — the answer to "is this stuck?".
  started_at: z.string().nullable().optional(),
  started_at_human: z.string().nullable().optional(),
  // Which phase apt is in, read out of its own output rather than timed.
  // Null until it has said something recognisable, which the screen shows as
  // "starting" instead of inventing a first step.
  current_step: z.string().nullable().optional(),
  // apt's own words, tail only. The step says where an install stopped; this
  // is the only thing that says why.
  output: z.string().nullable().optional(),
  source: z.string().nullable().optional(),
  // The version the panel itself runs on. Removing it would take the panel
  // offline from inside the panel, so the control is hidden, not just refused.
  in_use_by_panel: z.boolean().nullable().optional(),
  // How many sites pin this version, and up to five of them by name — "3 sites"
  // doesn't tell you whether removing this breaks staging or the shop.
  in_use_by: z.number().nullable().optional(),
  sites: z.array(z.string()).nullable().optional().default([]),
  sites_truncated: z.boolean().nullable().optional().default(false),
  lifecycle: lifecycleSchema.nullable().optional(),
  // The FPM unit. Starting and stopping it stays on Services — that is the same
  // job there as for nginx, and not the same thing as managing PHP.
  service: z.string().nullable().optional(),
  ini_path: z.string().nullable().optional(),
});

export const phpGroupSchema = z.object({
  manager: z.string().nullable().optional(),
  default: z.string().nullable().optional(),
  panel_version: z.string().nullable().optional(),
  // False on a box with no outbound network, or one that hasn't run the daily
  // refresh. Then every badge would read "unknown", so we show none at all.
  lifecycle_available: z.boolean().nullable().optional().default(false),
  versions: z.array(phpVersionSchema).default([]),
  system: z
    .object({ version: z.string(), path: z.string().nullable().optional() })
    .nullable()
    .optional(),
  // Was a flat string array before lifecycle data existed. Both are accepted so
  // a version skew between the two apps can't cost the whole response.
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
  // True only when every module is on in every SAPI: half-enabled behaves like
  // off (a site calling PDO still fails), so it reports as off.
  enabled: z.boolean().optional().default(false),
  // Compiled into PHP — nothing to switch, and the API refuses.
  builtin: z.boolean().optional().default(false),
  // PHP's json_encode turns an empty associative array into [], not {}, so a
  // built-in row arrives as `"sapis": []`. Accepting only an object threw away
  // the whole 96-row response over rows that have nothing to report.
  sapis: z
    .union([z.record(z.string(), z.boolean()), z.array(z.never())])
    .optional()
    .default({})
    .transform((value) => (Array.isArray(value) ? {} : value)),
});

export const phpExtensionsResponseSchema = z.object({
  extensions: z.array(phpExtensionSchema).default([]),
  // Only non-empty for the version the panel runs on.
  panel_required: z.array(z.string()).default([]),
});

export const phpIniResponseSchema = z.object({
  php_ini: z.object({
    version: z.string(),
    path: z.string(),
    contents: z.string(),
  }),
});
