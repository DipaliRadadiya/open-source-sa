import { z } from "zod";

export const PHP_SIZE_PATTERN = /^(-1|\d+[KMG]?)$/i;

export const PM_TYPES = ["ondemand", "dynamic", "static"];

export const MAX_CHILDREN = 100;

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

export function memoryCeilingBytes(memoryLimit, maxChildren) {
  return phpSizeToBytes(memoryLimit) * (Number(maxChildren) || 0);
}

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

const sizeWithUnit = z
  .string()
  .trim()
  .min(1, "requiredField")
  .max(12, "max12")
  .regex(/^\d+[KMG]$/i, "phpSizeUnit")
  .refine((value) => phpSizeToBytes(value) > 0, "phpSizeUnit");

const MIN_MEMORY = 16 * 1024 * 1024;

export const applicationPhpSchema = z
  .object({
    application_id: z.number(),
    php_version: z.string().nullish(),
    available_versions: z.array(z.string()).default([]),
    isolated: z.boolean().default(false),
    isolated_at: z.string().nullish(),
    isolation_supported: z.boolean().default(true),
    runs_as: z.string().nullish(),
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
        pm_start_servers: z.number().nullish(),
        pm_min_spare_servers: z.number().nullish(),
        pm_max_spare_servers: z.number().nullish(),
        pm_process_idle_timeout: z.number().nullish(),
        pm_max_spawn_rate: z.number().nullish(),
        open_basedir_enabled: z.boolean().default(false),
        open_basedir_paths: z.string().nullish(),
        disable_functions: z.string().nullish(),
        allow_url_fopen: z.boolean().default(true),
        php_timezone: z.string().nullish(),
        auto_prepend_file: z.string().nullish(),
        additional_directives: z.string().nullish(),
      })
      .passthrough(),
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
    open_basedir_effective: z.string().nullish(),
    open_basedir_live: z.string().nullish(),
    open_basedir_recommended: z.string().nullish(),

    suggested_disable_functions: z.string().default(""),
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

function optionalCount(max, message) {
  return z.preprocess(
    (value) => (value === "" || value === null || value === undefined ? null : value),
    z.coerce.number().int("integer").min(1, message).max(max, message).nullable(),
  );
}

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
  pm_start_servers: optionalCount(MAX_CHILDREN, "rangeWorkers"),
  pm_min_spare_servers: optionalCount(MAX_CHILDREN, "rangeWorkers"),
  pm_max_spare_servers: optionalCount(MAX_CHILDREN, "rangeWorkers"),
  pm_process_idle_timeout: optionalCount(86400, "rangeIdleTimeout"),
  pm_max_spawn_rate: optionalCount(1024, "rangeSpawnRate"),
  open_basedir_enabled: z.boolean().default(false),
  // No bare / (would allow everything) and no ..
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
        if (problem) {
          ctx.addIssue({ code: "custom", message: problem });
          return;
        }
      }
    })
    .default(""),
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
    .refine((value) => !value.includes(".."), "pathNoTraversal")
    .default(""),
  additional_directives: z
    .string()
    .trim()
    .max(4000, "max4000")
    .refine((value) => !/^\s*\[/m.test(value), "noSections")
    .refine(
      (value) =>
        value
          .split("\n")
          .map((line) => line.trim())
          .every(
            (line) =>
              line === "" ||
              /^[;#]/.test(line) ||
              /^(php_(admin_)?(value|flag)\[[A-Za-z_][A-Za-z0-9_.]*\]|env\[[A-Za-z_][A-Za-z0-9_]*\]|[A-Za-z_][A-Za-z0-9_.]*)\s*=/.test(line),
          ),
      "directivesPhpOnly",
    )
    .default(""),
});

// post_max_size must be >= upload limit or PHP drops $_POST.
export function phpSettingsFormSchemaFor(totalMemoryBytes = 0, applicationPath = "") {
  const root = String(applicationPath ?? "").replace(/\/+$/, "");
  return phpSettingsFormSchema.superRefine((values, ctx) => {
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
    if (!spareServersAgree(values)) {
      ctx.addIssue({ code: "custom", path: ["pm_start_servers"], message: "pmSpareOrder" });
    }
  });
}

export function spareServersAgree({ pm_start_servers: start, pm_min_spare_servers: min, pm_max_spare_servers: max, pm_max_children: children }) {
  const set = [start, min, max].filter((value) => value !== null && value !== undefined && value !== "");
  if (set.length === 0) return true;
  if (set.length < 3) return false;
  const [a, b, c, d] = [min, start, max, children].map(Number);
  return a <= b && b <= c && c <= d;
}

export function derivedSpareServers(children) {
  const c = Math.max(1, Number(children) || 1);
  return {
    pm_start_servers: Math.max(1, Math.ceil(c / 2)),
    pm_min_spare_servers: Math.max(1, Math.ceil(c / 4)),
    pm_max_spare_servers: Math.max(2, Math.ceil((c * 3) / 4)),
  };
}
