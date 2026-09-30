import { z } from "zod";

// Sentinel for "run as an OS account the panel doesn't manage" (root, www-data).
// The API takes system_user_id XOR username; this drives which one we send.
export const OTHER_USER = "__other__";

const PATH_TOKEN = "{path}";

// Linux account name, same rule the backend applies via getent passwd.
const linuxUsername = z
  .string()
  .trim()
  .regex(/^[a-z_][a-z0-9_-]{0,31}$/, "linuxUsername");

// 5-field cron, or a macro like @daily. The backend owns the real parse — this
// just catches obvious typos before a round-trip.
// No "@reboot". It is the one macro the backend's parser refuses
// (dragonmantank/cron-expression returns false for it while accepting the
// other seven), so advertising it here only bought a round trip and a 422.
const CRON_MACROS = ["@yearly", "@annually", "@monthly", "@weekly", "@daily", "@midnight", "@hourly"];
// What Linux cron itself reads in each field: a number, *, a 3-letter month or
// day name, a range, a step, comma-separated. The API's parser also takes L, W,
// ? and # ("last day", "2nd Monday") — cron refuses that line and ignores the
// whole file, so the job would never run while the panel showed a next run.
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
  .refine((v) => !v.includes(PATH_TOKEN), "unresolvedPath");

/*
 * Each job is a file in /etc/cron.d named after it, so a name can land on a
 * file that is already there: "panel-scheduler" replaced the panel's own
 * scheduler, and deleting the job deleted it. `RESERVED` is the backend's
 * `NotReservedCronFile` list; the panel-* names are the panel's own files,
 * which that list does not include yet.
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
  // A cron job is a way to run any command; as root that is the whole server
  // for anyone who can manage cron jobs.
  .refine(
    (d) => d.run_as !== OTHER_USER || (d.username ?? "").trim() !== "root",
    { message: "cronRootRefused", path: ["username"] },
  );

// Run-as is editable: the API re-points the job at the new account, checking
// it exists exactly as create does.
export const updateCronjobSchema = createCronjobSchema;

const systemUserRef = z.object({ id: z.number(), username: z.string() });

export const cronjobSchema = z.object({
  id: z.number(),
  name: z.string(),
  // Nullable in the table and raw in the resource: a job adopted by the sync
  // before it had a slug would have taken the whole cron list down.
  slug: z.string().nullish(),
  username: z.string(),
  system_user: systemUserRef.nullable().optional(),
  command: z.string(),
  expression: z.string(),
  active: z.boolean(),
  // The server's zone, not the viewer's: cron runs against the OS clock, so the
  // time only means anything paired with the zone it was computed in.
  timezone: z.string().optional(),
  // null for a paused job — an inactive schedule has no next run.
  next_run_at: z.string().nullable().optional(),
  next_run_at_human: z.string().nullable().optional(),
  // Key into the Logs endpoints for this job’s captured output. Null until the
  // job has been saved with output capture — show “nothing captured yet” rather
  // than opening an empty viewer.
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
