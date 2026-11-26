import { z } from "zod";

export const OTHER_USER = "__other__";

const PATH_TOKEN = "{path}";
const PHP_TOKEN = "{php}";

const linuxUsername = z
  .string()
  .trim()
  .regex(/^[a-z_][a-z0-9_-]{0,31}$/, "linuxUsername");

const CRON_MACROS = ["@yearly", "@annually", "@monthly", "@weekly", "@daily", "@midnight", "@hourly"];
const CRON_TOKEN = /^(\*|\d+|[a-z]{3})(-(\d+|[a-z]{3}))?(\/\d+)?$/i;
const isMacro = (v) => CRON_MACROS.includes(v.toLowerCase());
const expressionField = z
  .string()
  .trim()
  .min(1, "required_expression")
  .refine((v) => v.toLowerCase() !== "@reboot", "cronReboot")
  .refine((v) => v.toLowerCase() === "@reboot" || isMacro(v) || v.split(/\s+/).length === 5, "cronExpression")
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
  .refine((v) => !v.includes(PATH_TOKEN), "unresolvedPath")
  .refine((v) => !v.includes(PHP_TOKEN), "unresolvedPhp")
  .refine((v) => !hasShellComment(v), "cronTrailingComment");

export function hasShellComment(command) {
  let quote = null;
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

const RESERVED_CRON_FILES = [
  "php", "e2scrub_all", "sysstat", "anacron", "certbot", "mdadm",
  "popularity-contest", "ntpsec", "plocate", "mlocate", "apt-compat",
  "dpkg", "cron", "crontab", "apport", "update-notifier-common",
];

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
    application_id: z.number().int().positive().nullable().optional(),
  })
  .refine(
    (d) => d.run_as !== OTHER_USER || linuxUsername.safeParse(d.username ?? "").success,
    { message: "linuxUsername", path: ["username"] },
  )
  .refine(
    (d) => d.run_as !== OTHER_USER || (d.username ?? "").trim() !== "root",
    { message: "cronRootRefused", path: ["username"] },
  );

export const updateCronjobSchema = createCronjobSchema;

const systemUserRef = z.object({ id: z.number(), username: z.string() });

const runFields = {
  status: z.string(),
  exit_code: z.number().nullable().optional(),
  manual: z.boolean().optional().default(false),
  overlapped: z.boolean().optional().default(false),
  started_at: z.string().nullable().optional(),
  finished_at: z.string().nullable().optional(),
  duration_seconds: z.number().nullable().optional(),
};

export const cronjobRunSchema = z.object({
  ...runFields,
  output: z.string().nullable().optional(),
  output_truncated: z.boolean().optional().default(false),
});

export const cronjobRunsResponseSchema = z.object({
  runs: z.array(cronjobRunSchema).default([]),
  timezone: z.string().nullable().optional(),
});

export const cronjobSchema = z.object({
  id: z.number(),
  name: z.string(),
  slug: z.string().nullish(),
  username: z.string(),
  system_user: systemUserRef.nullable().optional(),
  command: z.string(),
  application_id: z.number().nullish(),
  php_version: z.string().nullish(),
  matches_site_php: z.boolean().nullish(),
  expression: z.string(),
  active: z.boolean(),
  timezone: z.string().optional(),
  next_run_at: z.string().nullable().optional(),
  next_run_at_human: z.string().nullable().optional(),
  log_key: z.string().nullable().optional(),
  last_run: z.object(runFields).nullable().optional(),
  running: z.boolean().optional(),
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
    usernames: z.array(z.string()).optional(),
    cron_running: z.boolean().nullable().optional(),
  }),
});
