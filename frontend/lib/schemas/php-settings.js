import { z } from "zod";

/**
 * PHP's own size vocabulary — `128M`, `1G`, `-1` for unlimited.
 *
 * A copy of `SavePhpSettingsRequest`'s rule, kept in PHP's units rather than
 * a number of megabytes so what someone types is what lands in the pool file
 * and they can read it back. Covered in `tests/backend-mirror.test.mjs`.
 */
export const PHP_SIZE_PATTERN = /^(-1|\d+[KMG]?)$/i;

export const PM_TYPES = ["ondemand", "dynamic", "static"];

/** `SavePhpSettingsRequest::MAX_CHILDREN`. */
export const MAX_CHILDREN = 100;

/**
 * `256M` → bytes, mirroring `ApplicationPhpSettings::toBytes()`.
 *
 * `-1` counts as 128M rather than zero: unlimited cannot be budgeted, and
 * treating it as nothing would report a server full of unlimited pools as
 * comfortably empty.
 */
export function phpSizeToBytes(value) {
  const trimmed = String(value ?? "").trim();

  if (trimmed === "-1") return 128 * 1024 * 1024;

  const unit = trimmed.slice(-1).toLowerCase();
  const number = Number.parseInt(trimmed, 10);

  if (Number.isNaN(number)) return 0;

  if (unit === "g") return number * 1024 * 1024 * 1024;
  if (unit === "m") return number * 1024 * 1024;
  if (unit === "k") return number * 1024;
  return number;
}

/**
 * What this site could take at full tilt, mirroring
 * `ApplicationPhpSettings::memoryCeilingBytes()`.
 *
 * The API returns this figure too, but only for the SAVED settings — the whole
 * point of the budget bar is that it moves while someone is still deciding, so
 * the arithmetic has to exist on this side as well.
 */
export function memoryCeilingBytes(memoryLimit, maxChildren) {
  return phpSizeToBytes(memoryLimit) * (Number(maxChildren) || 0);
}

/**
 * The budget with unsaved numbers folded in.
 *
 * `committed` from the API already includes this site's saved ceiling, so it
 * is taken back out before the proposed one goes in — otherwise every keystroke
 * would count the site twice.
 */
export function budgetWith(memory, memoryLimit, maxChildren) {
  const total = memory?.total ?? 0;
  const others = Math.max(0, (memory?.committed ?? 0) - (memory?.this_site ?? 0));
  const thisSite = memoryCeilingBytes(memoryLimit, maxChildren);
  const committed = others + thisSite;

  return {
    total,
    others,
    thisSite,
    committed,
    available: Math.max(0, total - committed),
    overCommitted: total > 0 && committed > total,
    sites: memory?.sites ?? 1,
  };
}

const size = z
  .string()
  .trim()
  .min(1, "required")
  .max(12, "max12")
  .regex(PHP_SIZE_PATTERN, "phpSize");

/** `GET /applications/{id}/php`. */
export const applicationPhpSchema = z
  .object({
    application_id: z.number(),
    php_version: z.string().nullish(),
    available_versions: z.array(z.string()).default([]),
    isolated: z.boolean().default(false),
    isolated_at: z.string().nullish(),
    isolation_supported: z.boolean().default(true),
    runs_as: z.string().nullish(),
    // False when the pool file no longer matches what the panel would write —
    // someone edited it by hand, and saving would overwrite their work.
    managed: z.boolean().default(true),
    settings: z
      .object({
        memory_limit: z.string().default("128M"),
        upload_max_filesize: z.string().default("2M"),
        post_max_size: z.string().default("8M"),
        max_execution_time: z.number().default(30),
        max_input_time: z.number().default(60),
        max_input_vars: z.number().default(1000),
        session_gc_maxlifetime: z.number().default(1440),
        pm_type: z.string().default("ondemand"),
        pm_max_children: z.number().default(5),
        pm_max_requests: z.number().default(500),
        open_basedir_enabled: z.boolean().default(false),
        // Only the paths the user ADDED. The three the backend always prepends
        // (app root, this site's sessions, /tmp) are not in here.
        open_basedir_paths: z.string().nullish(),
        disable_functions: z.string().nullish(),
        allow_url_fopen: z.boolean().default(true),
        php_timezone: z.string().nullish(),
        auto_prepend_file: z.string().nullish(),
        additional_directives: z.string().nullish(),
      })
      .passthrough(),
    // True for each directive this site has explicitly set; false means the
    // value in `settings` is the panel default showing through. The client
    // cannot work this out for itself — an inherited value and an override that
    // happens to equal the default look identical — so it is the only thing
    // that makes "Reset to default" possible.
    overridden: z.record(z.string(), z.boolean()).default({}),
    presets: z
      .array(
        z.object({
          key: z.string(),
          title: z.string(),
          description: z.string().nullish(),
          pm_type: z.string(),
          pm_max_children: z.number(),
        }),
      )
      .default([]),
    memory: z
      .object({
        total: z.number().default(0),
        committed: z.number().default(0),
        available: z.number().default(0),
        over_committed: z.boolean().default(false),
        sites: z.number().default(0),
        this_site: z.number().default(0),
      })
      .default({ total: 0, committed: 0, available: 0, over_committed: false, sites: 0, this_site: 0 }),
    /**
     * Three answers to "what is open_basedir here", and they can all differ.
     *
     * `effective`   — what the panel would write from the stored row. Null when
     *                 the setting is off.
     * `live`        — what the pool file on disk actually says, READ not
     *                 derived. Null means the panel could not find out (no pool
     *                 file, or the pool sets nothing) — which is not the same
     *                 as "no restriction" and must never render as one.
     * `recommended` — the whole value you would get by turning it on and adding
     *                 nothing. Note this is the RESULT, not a value to paste
     *                 into the paths box: the box holds extras only.
     *
     * `live` differing from `effective` means PHP is enforcing something other
     * than what this screen says — someone hand-edited the pool, or put their
     * own `open_basedir` in the additional-directives box, where it lands after
     * ours and wins.
     */
    open_basedir_effective: z.string().nullish(),
    open_basedir_live: z.string().nullish(),
    open_basedir_recommended: z.string().nullish(),

    suggested_disable_functions: z.string().default(""),
    // Starting points for `disable_functions`, safest first, titles and
    // descriptions already localised by the API. Read this rather than the
    // flat `suggested_disable_functions` above — a third preset should not
    // need a frontend change.
    disable_functions_presets: z
      .array(
        z.object({
          key: z.string(),
          title: z.string(),
          description: z.string().default(""),
          functions: z.string().default(""),
        }),
      )
      .default([]),
  })
  .passthrough();

export const applicationPhpResponseSchema = z.object({ php: applicationPhpSchema });

/**
 * The form, mirroring `SavePhpSettingsRequest`.
 *
 * Every bound here is the backend's own. They are repeated rather than
 * discovered from a 422 because a number this screen accepts and the server
 * refuses is a round trip that teaches nothing.
 */
export const phpSettingsFormSchema = z.object({
  php_version: z.string().min(1, "required"),
  memory_limit: size,
  upload_max_filesize: size,
  post_max_size: size,
  max_execution_time: z.coerce.number().int("integer").min(0, "range").max(3600, "range"),
  max_input_time: z.coerce.number().int("integer").min(-1, "range").max(3600, "range"),
  max_input_vars: z.coerce.number().int("integer").min(100, "range").max(100000, "range"),
  session_gc_maxlifetime: z.coerce.number().int("integer").min(60, "range").max(604800, "range"),
  pm_type: z.enum(PM_TYPES),
  pm_max_children: z.coerce.number().int("integer").min(1, "range").max(MAX_CHILDREN, "range"),
  pm_max_requests: z.coerce.number().int("integer").min(0, "range").max(100000, "range"),
  open_basedir_enabled: z.boolean().default(false),
  /**
   * Extra folders, one per line. The rules are the backend's own
   * (`SavePhpSettingsRequest`), repeated here so nobody learns them from a 422:
   * absolute only (a relative path resolves against the worker's working
   * directory, which nobody can see), never bare `/` (that allows everything,
   * so the pool would claim open_basedir is on while enforcing nothing), and no
   * `..`.
   */
  open_basedir_paths: z
    .string()
    .trim()
    .max(2000, "max2000")
    .superRefine((value, ctx) => {
      for (const raw of value.split(/[:\n,]+/)) {
        const path = raw.trim();
        if (path === "") continue;
        const problem = !path.startsWith("/")
          ? "basedirAbsolute"
          : path.replace(/\/+$/, "") === ""
            ? "basedirRoot"
            : path.includes("..")
              ? "basedirTraversal"
              : null;
        // No placeholder in the message: FormMessage translates the key with
        // no values, so a `{path}` in the string would throw at render.
        if (problem) {
          ctx.addIssue({ code: "custom", message: problem });
          return;
        }
      }
    })
    .default(""),
  // A comma-separated list of function names and nothing else: it lands in the
  // pool file verbatim.
  disable_functions: z
    .string()
    .trim()
    .max(2000, "max2000")
    .regex(/^[A-Za-z0-9_,\s]*$/, "functionList")
    .default(""),
  allow_url_fopen: z.boolean().default(true),
  php_timezone: z.string().trim().default(""),
  auto_prepend_file: z
    .string()
    .trim()
    .max(255, "max255")
    // `not_regex:/\.\./` on the backend.
    .refine((value) => !value.includes(".."), "noTraversal")
    .default(""),
  // Ini, so newlines are fine; a `[section]` header is not — it would start a
  // second pool inside this file.
  additional_directives: z
    .string()
    .trim()
    .max(4000, "max4000")
    .refine((value) => !/^\s*\[/m.test(value), "noSections")
    .default(""),
});
