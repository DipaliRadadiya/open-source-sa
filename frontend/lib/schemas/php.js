import { z } from "zod";

/**
 * PHP versions and extensions. The API gives Node the same shape; the
 * differences are in the data (PHP has extensions and an ini; Node has an
 * unmanaged "system" install).
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
  // disk for this version, so its extensions and php.ini 404.
  status: z.string().nullable().optional(),
  // Why it failed, in the API's own words, plus the support reference.
  reason: z.string().nullable().optional(),
  message: z.string().nullable().optional(),
  reference: z.string().nullable().optional(),
  // When the install began, to tell whether it is stuck.
  started_at: z.string().nullable().optional(),
  started_at_human: z.string().nullable().optional(),
  // Which phase apt is in, parsed from its output. Null until recognisable;
  // shown as "starting".
  current_step: z.string().nullable().optional(),
  // Tail of apt's output: the only thing that says why an install stopped.
  output: z.string().nullable().optional(),
  source: z.string().nullable().optional(),
  // The version the panel itself runs on; removing it would take the panel
  // offline, so the control is hidden.
  in_use_by_panel: z.boolean().nullable().optional(),
  // How many sites pin this version, and up to five of them by name.
  in_use_by: z.number().nullable().optional(),
  sites: z.array(z.string()).nullable().optional().default([]),
  sites_truncated: z.boolean().nullable().optional().default(false),
  lifecycle: lifecycleSchema.nullable().optional(),
  // The FPM unit. Starting and stopping it stays on Services.
  service: z.string().nullable().optional(),
  ini_path: z.string().nullable().optional(),
  /*
   * Base packages this version should have and does not. Non-empty means the
   * interpreter arrived some other way (e.g. `openlitespeed` pulls in `lsphp83`)
   * and lacks common extensions.
   *
   * Already filtered server-side to packages apt knows about
   * (`PhpRuntime::missingBasePackages`), so LiteSpeed's compiled-in modules
   * never appear; nothing to special-case here.
   */
  missing_packages: z.array(z.string()).nullable().optional().default([]),
});

export const phpGroupSchema = z.object({
  manager: z.string().nullable().optional(),
  default: z.string().nullable().optional(),
  panel_version: z.string().nullable().optional(),
  // False with no outbound network or before the daily refresh; then no
  // badges are shown rather than all "unknown".
  lifecycle_available: z.boolean().nullable().optional().default(false),
  versions: z.array(phpVersionSchema).default([]),
  system: z
    .object({ version: z.string(), path: z.string().nullable().optional() })
    .nullable()
    .optional(),
  // Accepts the legacy flat string array too, so version skew between backend
  // and frontend cannot fail the whole response.
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
  // True only when every module is on in every SAPI; half-enabled reports as off.
  enabled: z.boolean().optional().default(false),
  // Compiled into PHP: nothing to switch, and the API refuses.
  builtin: z.boolean().optional().default(false),
  // The last operation on this extension: installing | ready | failed.
  status: z.string().nullish(),
  current_step: z.string().nullish(),
  output: z.string().nullish(),
  reason: z.string().nullish(),
  message: z.string().nullish(),
  reference: z.string().nullish(),
  // PHP's json_encode turns an empty associative array into [], so built-in
  // rows arrive as `"sapis": []`.
  sapis: z
    .union([z.record(z.string(), z.boolean()), z.array(z.never())])
    .optional()
    .default({})
    .transform((value) => (Array.isArray(value) ? {} : value)),
});

/**
 * The ionCube Loader's state for one PHP version. `supported: false` is normal
 * (no loader exists for PHP 8.0). `status` uses the install tracker's
 * vocabulary plus `idle`, so `isInFlight` works on it unchanged.
 */
export const ionCubeSchema = z.object({
  supported: z.boolean().default(false),
  installed: z.boolean().default(false),
  php_version: z.string().nullish(),
  loader_version: z.string().nullish(),
  sha256: z.string().nullish(),
  path: z.string().nullish(),
  // `panel` | `external` | null. An external loader is shown but never
  // touched: Install and Remove 422.
  source: z.string().nullish(),
  status: z.string().nullish(),
  // `reason` is a code; `message` is the sentence to show.
  reason: z.string().nullish(),
  message: z.string().nullish(),
  reference: z.string().nullish(),
});

export const ionCubeResponseSchema = z.object({ ioncube: ionCubeSchema });

export const phpExtensionsResponseSchema = z.object({
  extensions: z.array(phpExtensionSchema).default([]),
  // Only non-empty for the version the panel runs on.
  panel_required: z.array(z.string()).default([]),
  // False on OpenLiteSpeed: installed extensions cannot be switched off (the
  // API 422s). Defaults true for older APIs.
  toggle_supported: z.boolean().default(true),
});

export const phpIniResponseSchema = z.object({
  php_ini: z.object({
    version: z.string(),
    path: z.string(),
    contents: z.string(),
  }),
});
