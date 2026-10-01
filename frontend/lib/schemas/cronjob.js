import { z } from "zod";

// Sentinel for "run as an OS account the panel doesn't manage" (root, www-data).
// The API takes system_user_id XOR username; this decides which one is sent.
export const OTHER_USER = "__other__";

const PATH_TOKEN = "{path}";

// Linux account name, same rule the backend applies via getent passwd.
const linuxUsername = z
  .string()
  .trim()
  .regex(/^[a-z_][a-z0-9_-]{0,31}$/, "linuxUsername");

// 5-field cron, or a macro like @daily. The backend owns the real parse.
// No "@reboot": the backend's parser (dragonmantank/cron-expression) refuses it.
const CRON_MACROS = ["@yearly", "@annually", "@monthly", "@weekly", "@daily", "@midnight", "@hourly"];
// What Linux cron itself reads in each field: a number, *, a 3-letter name, a
// range, a step, comma-separated. The API's parser also takes L, W, ? and #,
// but cron rejects the line and ignores the whole file.
const CRON_TOKEN = /^(\*|\d+|[a-z]{3})(-(\d+|[a-z]{3}))?(\/\d+)?$/i;
const isMacro = (v) => CRON_MACROS.includes(v.toLowerCase());
const expressionField = z
  .string()
  .trim()
  .min(1, "required_expression")
  .refine((v) => isMacro(v) || v.split(/\s+/).length === 5, "cronExpression")
  .refine(
    (v) => isMacro(v) || v.split(/\s+/).length !== 5 || v.split(/\s+/).every((f) => f.split(",").every((t) => CRON_TOKEN.test(t))),
    "cronUnsupported",
  );

const commandField = z
  .string()
  .trim()
  .min(1, "required_command")
  .max(1000, "max1000")
  .refine((v) => !/[\r\n]/.test(v), "noLineBreaks")
  // Preset commands ship with a {path} placeholder; the API 422s if it survives.
  .refine((v) => !v.includes(PATH_TOKEN), "unresolvedPath")
  // The panel appends its own logging after the command, so a trailing
  // `# note` would comment that out.
  .refine((v) => !hasShellComment(v), "cronTrailingComment");

/**
 * Is there a shell comment in this command: a `#` at the start of a word,
 * outside quotes? `echo "#tag"`, `curl x/#frag` and `$#` are not comments.
 */
export function hasShellComment(command) {
  let quote = null;
  // True at the start and after unescaped whitespace: where a word begins.
  let wordStart = true;
  for (let i = 0; i < command.length; i += 1) {
    const c = command[i];
    if (c === "\\" && quote !== "'") {
      i += 1;
      wordStart = false;
      continue;
    }
    if (quote) {
      if (c === quote) quote = null;
      continue;
    }
    if (c === "'" || c === '"') {
      quote = c;
      wordStart = false;
      continue;
    }
    if (c === "#" && wordStart) return true;
    wordStart = /\s|;|&|\|/.test(c);
  }
  return false;
}

/*
 * Each job is a file in /etc/cron.d named after it, so a name must not land on
 * an existing file. Mirrors the backend's `NotReservedCronFile` list; panel-*
 * names (the panel's own files) are refused too.
 */
const RESERVED_CRON_FILES = [
  "php", "e2scrub_all", "sysstat", "anacron", "certbot", "mdadm",
  "popularity-contest", "ntpsec", "plocate", "mlocate", "apt-compat",
  "dpkg", "cron", "crontab", "apport", "update-notifier-common",
];

// Laravel's Str::slug, closely enough for the comparison above.
function cronSlug(value) {
  return String(value ?? "")
    .normalize("NFKD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .replace(/[^a-z0-9_\s-]/g, "")
    .replace(/[\s_-]+/g, (match) => (match.includes("_") && !/[\s-]/.test(match) ? "_" : "-"))
    .replace(/^-+|-+$/g, "");
}

const nameField = z
  .string()
  .trim()
  .min(1, "required_name")
  .max(255, "max255")
  .refine((v) => !/[\r\n]/.test(v), "noLineBreaks")
  .refine((v) => {
    const slug = cronSlug(v);
    return !RESERVED_CRON_FILES.includes(slug) && !/^panel(-|$)/.test(slug);
  }, "cronNameReserved");

export const createCronjobSchema = z
  .object({
    name: nameField,
    run_as: z.string().min(1, "required_runAs"),
    username: z.string().trim().optional(),
    command: commandField,
    expression: expressionField,
    active: z.boolean(),
  })
  // "Other OS user" turns the free-text username into the required field.
  .refine(
    (d) => d.run_as !== OTHER_USER || linuxUsername.safeParse(d.username ?? "").success,
    { message: "linuxUsername", path: ["username"] },
  )
  // As root, a cron job is the whole server for anyone who can manage cron jobs.
  .refine(
    (d) => d.run_as !== OTHER_USER || (d.username ?? "").trim() !== "root",
    { message: "cronRootRefused", path: ["username"] },
  );

// Run-as is editable: the API re-points the job and validates the account as on create.
export const updateCronjobSchema = createCronjobSchema;

const systemUserRef = z.object({ id: z.number(), username: z.string() });

export const cronjobSchema = z.object({
  id: z.number(),
  name: z.string(),
  // Nullable: jobs adopted by sync may have no slug.
  slug: z.string().nullish(),
  username: z.string(),
  system_user: systemUserRef.nullable().optional(),
  command: z.string(),
  expression: z.string(),
  active: z.boolean(),
  // The server's zone, not the viewer's: cron runs on the OS clock.
  timezone: z.string().optional(),
  // null for a paused job: an inactive schedule has no next run.
  next_run_at: z.string().nullable().optional(),
  next_run_at_human: z.string().nullable().optional(),
  // Key into the Logs endpoints for this job's output. Null until saved with
  // output capture; show "nothing captured yet" instead of an empty viewer.
  log_key: z.string().nullable().optional(),
  created_at: z.string().optional(),
  created_at_human: z.string().optional(),
});

export const cronjobsResponseSchema = z.object({
  cronjobs: z.array(cronjobSchema),
  meta: z.object({
    current_page: z.number(),
    per_page: z.number(),
    total: z.number(),
    last_page: z.number(),
  }),
});
