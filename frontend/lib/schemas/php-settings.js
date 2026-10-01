import { z } from "zod";

/** A copy of `SavePhpSettingsRequest`'s rule; covered in `tests/backend-mirror.test.mjs`. */
export const PHP_SIZE_PATTERN = /^(-1|\d+[KMG]?)$/i;

export const PM_TYPES = ["ondemand", "dynamic", "static"];

/** `SavePhpSettingsRequest::MAX_CHILDREN`. */
export const MAX_CHILDREN = 100;

// Mirrors `ApplicationPhpSettings::toBytes()`; `-1` counts as 128M so unlimited pools
// are not reported as empty.
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

// Mirrors `ApplicationPhpSettings::memoryCeilingBytes()`: the API only returns it for
// saved settings, and the budget bar needs it for unsaved ones.
export function memoryCeilingBytes(memoryLimit, maxChildren) {
  return phpSizeToBytes(memoryLimit) * (Number(maxChildren) || 0);
}

/** `committed` already includes this site's saved ceiling, so it is subtracted first. */
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

// Stricter than the API: a bare `64` is 64 BYTES, and `0`/`-1` switch the limit off.
const sizeWithUnit = z
  .string()
  .trim()
  .min(1, "requiredField")
  .max(12, "max12")
  .regex(/^\d+[KMG]$/i, "phpSizeUnit")
  .refine((value) => phpSizeToBytes(value) > 0, "phpSizeUnit");

const MIN_MEMORY = 16 * 1024 * 1024;

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
    // False when the pool file was hand-edited (saving would overwrite it);
    // null when the pool file could not be read.
    managed: z.boolean().nullable().default(true),
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
        // Only the user-added paths; the backend always prepends app root,
        // this site's sessions and /tmp.
        open_basedir_paths: z.string().nullish(),
        disable_functions: z.string().nullish(),
        allow_url_fopen: z.boolean().default(true),
        php_timezone: z.string().nullish(),
        auto_prepend_file: z.string().nullish(),
        additional_directives: z.string().nullish(),
      })
      .passthrough(),
    // False means the panel default shows through; needed for "Reset to default", since
    // an override equal to the default looks identical.
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
    // `effective`: what the panel would write (null when off). `live`: the pool file on
    // disk, null meaning undetermined, never "no restriction". `recommended`: not for the paths box.
    open_basedir_effective: z.string().nullish(),
    open_basedir_live: z.string().nullish(),
    open_basedir_recommended: z.string().nullish(),

    suggested_disable_functions: z.string().default(""),
    // `disable_functions` starting points, safest first, localized by the API.
    // Prefer this over `suggested_disable_functions`.
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

/** Mirrors `SavePhpSettingsRequest`'s bounds so values are refused before a 422. */
export const phpSettingsFormSchema = z.object({
  php_version: z.string().min(1, "requiredField"),
  memory_limit: sizeWithUnit.refine((value) => phpSizeToBytes(value) >= MIN_MEMORY, "phpMemoryMin"),
  upload_max_filesize: sizeWithUnit,
  post_max_size: sizeWithUnit,
  max_execution_time: z.coerce.number().int("integer").min(0, "rangeSeconds3600").max(3600, "rangeSeconds3600"),
  max_input_time: z.coerce.number().int("integer").min(-1, "rangeInputTime").max(3600, "rangeInputTime"),
  max_input_vars: z.coerce.number().int("integer").min(100, "rangeInputVars").max(100000, "rangeInputVars"),
  session_gc_maxlifetime: z.coerce.number().int("integer").min(60, "rangeSession").max(604800, "rangeSession"),
  pm_type: z.enum(PM_TYPES),
  pm_max_children: z.coerce.number().int("integer").min(1, "rangeWorkers").max(MAX_CHILDREN, "rangeWorkers"),
  pm_max_requests: z.coerce.number().int("integer").min(0, "rangeMaxRequests").max(100000, "rangeMaxRequests"),
  open_basedir_enabled: z.boolean().default(false),
  // Backend rules: absolute only, never bare `/` (would allow everything), and no `..`.
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
  // Function names and commas only: it lands in the pool file verbatim.
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
    .refine((value) => !value.includes(".."), "pathNoTraversal")
    .default(""),
  // Ini, so newlines are fine; a `[section]` header would start a second pool.
  additional_directives: z
    .string()
    .trim()
    .max(4000, "max4000")
    .refine((value) => !/^\s*\[/m.test(value), "noSections")
    // PHP settings only: a pool line such as `user = root` would change who the pool runs as.
    .refine(
      (value) =>
        value
          .split("\n")
          .map((line) => line.trim())
          .every((line) => line === "" || /^[;#]/.test(line) || /^php_(admin_)?(value|flag)\[[A-Za-z0-9_.]+\]\s*=/.test(line)),
      "directivesPhpOnly",
    )
    .default(""),
});

// Rules that need server facts: memory within the machine's, and a POST limit at least
// the upload limit (PHP otherwise drops $_POST).
export function phpSettingsFormSchemaFor(totalMemoryBytes = 0, applicationPath = "") {
  const root = String(applicationPath ?? "").replace(/\/+$/, "");
  return phpSettingsFormSchema.superRefine((values, ctx) => {
    // PHP runs this file on every request; keep it inside the site.
    const prepend = values.auto_prepend_file ?? "";
    if (prepend.startsWith("/") && (!root || !prepend.startsWith(`${root}/`))) {
      ctx.addIssue({ code: "custom", path: ["auto_prepend_file"], message: "prependOutsideSite" });
    }
    if (totalMemoryBytes > 0 && phpSizeToBytes(values.memory_limit) > totalMemoryBytes) {
      ctx.addIssue({ code: "custom", path: ["memory_limit"], message: "phpMemoryMax" });
    }
    if (phpSizeToBytes(values.post_max_size) < phpSizeToBytes(values.upload_max_filesize)) {
      ctx.addIssue({ code: "custom", path: ["post_max_size"], message: "postBelowUpload" });
    }
  });
}
