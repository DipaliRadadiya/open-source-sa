import { z } from "zod";

/** The windows the History filter offers, and the only ones it honours. */
export const BACKUP_PERIODS = ["7", "30", "90"];

export const BACKUP_TYPES = ["full", "filesystem", "database"];

// Copy of `BackupTarget::CRON`'s `0 2 * * *`; shown only when the API sends `null`.
export const BACKUP_DEFAULT_TIME = "02:00";

// `time`: `minute` (hourly, only the minute of `schedule_time` is used), `time`, or null (manual).
export const backupTargetOptionsSchema = z.object({
  frequencies: z.array(
    z.object({
      value: z.string(),
      label: z.string(),
      time: z.enum(["minute", "time"]).nullable(),
      hint: z.string().nullish(),
    }),
  ),
  default_frequency: z.string(),
  types: z.array(z.object({ value: z.string(), label: z.string() })),
  retention: z.object({ min: z.number(), max: z.number() }),
  // The panel's clock, which `schedule_time` is read in — not the server's.
  timezone: z.string().nullish(),
});

// Mirrors `RestoreBackupRequest::withValidator()`.
export function restorableTypes(backupType) {
  if (backupType === "full") return ["full", "filesystem", "database"];
  if (backupType === "filesystem") return ["filesystem"];
  if (backupType === "database") return ["database"];
  return [];
}

// There is no "completed": `verified` is success. Use this constant, not a literal.
export const BACKUP_SUCCEEDED = "verified";

/** Only a verified backup can be restored — the first guard in the request. */
export const RESTORABLE_STATUS = BACKUP_SUCCEEDED;

/** Whether this run left an archive to download or restore from. */
export function backupHasArchive(status) {
  return status === BACKUP_SUCCEEDED;
}

/** Statuses that mean a run is still in flight, so a second must not start. */
export const BACKUP_IN_FLIGHT = ["pending", "running", "verifying"];
export const RESTORE_IN_FLIGHT = ["pending", "running"];

/** `RestoreStatus` on the backend, and what `filter[status]` will accept. */
export const RESTORE_STATUSES = ["pending", "running", "succeeded", "failed"];

// What `filter[status]` accepts, in the filter's order.
export const BACKUP_STATUSES = ["verified", "verifying", "running", "pending", "failed"];

export const backupSchema = z
  .object({
    id: z.number(),
    /* The archive's name in the bucket; older rows have none. */
    uid: z.string().nullish(),
    application_id: z.number().nullish(),
    type: z.string(),
    type_title: z.string().nullish(),
    // Taken just before a restore, exempt from retention.
    is_safety: z.boolean().default(false),
    status: z.string(),
    status_title: z.string().nullish(),
    // Branch on `reason`, display `reason_title`.
    reason: z.string().nullish(),
    reason_title: z.string().nullish(),
    size_bytes: z.number().nullish(),
    // Absent, not null, unless eager-loaded, so "no destination" and "not asked for" differ.
    storage_destination_name: z.string().nullish(),
    // Sent on the server-wide history, where a row has to name its own site.
    application_name: z.string().nullish(),
    application_domain: z.string().nullish(),
    reference: z.string().nullish(),
    // The run's last heartbeat — what decides whether Clear can work.
    progress_at: z.string().nullish(),
    started_at: z.string().nullish(),
    finished_at: z.string().nullish(),
    verified_at: z.string().nullish(),
    created_at: z.string().nullish(),
    created_at_human: z.string().nullish(),
  })
  .passthrough();

const metaSchema = z.object({
  current_page: z.number().default(1),
  per_page: z.number().default(20),
  total: z.number().default(0),
  last_page: z.number().default(1),
  // Per-status totals across the whole filtered set, not just this page.
  counts: z
    .object({
      total: z.number().default(0),
      pending: z.number().default(0),
      running: z.number().default(0),
      verifying: z.number().default(0),
      completed: z.number().default(0),
      failed: z.number().default(0),
    })
    .nullish(),
});

/** One backup, as `GET /backups/{id}` returns it. */
export const backupResponseSchema = z.object({ backup: backupSchema });

export const backupsResponseSchema = z.object({
  backups: z.array(backupSchema).default([]),
  meta: metaSchema.default({ current_page: 1, per_page: 20, total: 0, last_page: 1 }),
});

export const backupTargetSchema = z
  .object({
    id: z.number(),
    application_id: z.number(),
    storage_destination_id: z.number(),
    // Only present when the controller eager-loads the relation.
    storage_destination_name: z.string().nullish(),
    type: z.string(),
    type_title: z.string().nullish(),
    retention_count: z.number(),
    frequency: z.string(),
    frequency_title: z.string().nullish(),
    // "HH:MM" in `timezone` below (the panel's clock, not the server's).
    schedule_time: z.string().nullish(),
    enabled: z.boolean().default(true),
    file_excludes: z.array(z.string()).default([]),
    database_excludes: z.array(z.string()).default([]),
    last_run_at: z.string().nullish(),
    last_run_at_human: z.string().nullish(),
    // Never recompute these from cron constants; a frontend copy would drift.
    next_run_at: z.string().nullish(),
    next_run_at_human: z.string().nullish(),
    /* Deliberately NOT a cron job's `timezone` (server OS clock): backups resolve against
     * the app timezone. Do not "fix" one to match the other. */
    timezone: z.string().nullish(),
    is_due: z.boolean().nullish(),
    created_at: z.string().nullish(),
    updated_at: z.string().nullish(),
  })
  .passthrough();

// Nested so "not configured" (null) differs from "configured with nothing set".
export const applicationBackupSchema = z
  .object({
    application_id: z.number(),
    application_name: z.string(),
    application_domain: z.string(),
    backup_target: backupTargetSchema.nullable(),
    last_backup: backupSchema.nullable(),
  })
  .passthrough();

// `matched` is what the current search and filter hit; `protected`/`unprotected` are unused here.
export const backupTargetsResponseSchema = z.object({
  backup_targets: z.array(applicationBackupSchema).default([]),
  meta: z
    .object({
      total: z.number(),
      protected: z.number(),
      unprotected: z.number(),
      matched: z.number().default(0),
      current_page: z.number().default(1),
      per_page: z.number().default(10),
      last_page: z.number().default(1),
    })
    .default({
      total: 0,
      protected: 0,
      unprotected: 0,
      matched: 0,
      current_page: 1,
      per_page: 10,
      last_page: 1,
    }),
});

/** `backup_target` is null for a site nobody has configured yet. */
export const backupTargetResponseSchema = z.object({
  backup_target: backupTargetSchema.nullable(),
});

export const restoreSchema = z
  .object({
    id: z.number(),
    // Null once the source backup is deleted; required would reject the whole response.
    backup_id: z.number().nullish(),
    application_id: z.number().nullish(),
    // Null when the site has since been deleted.
    application_name: z.string().nullish(),
    application_domain: z.string().nullish(),
    type: z.string(),
    type_title: z.string().nullish(),
    status: z.string(),
    status_title: z.string().nullish(),
    current_step: z.string().nullish(),
    current_step_title: z.string().nullish(),
    // A progress bar needs no frontend copy of the step list.
    step_number: z.number().nullish(),
    total_steps: z.number().nullish(),
    reason: z.string().nullish(),
    reason_title: z.string().nullish(),
    // The way back out of a restore that put the wrong thing live.
    safety_backup_id: z.number().nullish(),
    rollback_path: z.string().nullish(),
    reference: z.string().nullish(),
    started_at: z.string().nullish(),
    started_at_human: z.string().nullish(),
    finished_at: z.string().nullish(),
    finished_at_human: z.string().nullish(),
  })
  .passthrough();

export const restoreResponseSchema = z.object({ restore: restoreSchema });

export const restoresResponseSchema = z.object({
  restores: z.array(restoreSchema).default([]),
  meta: metaSchema.default({ current_page: 1, per_page: 20, total: 0, last_page: 1 }),
});

// `retention_count` floor of 1: zero would prune the backup the run had just taken.
export function backupTargetFormSchema(options) {
  const frequencies = options?.frequencies.map((f) => f.value) ?? [];
  const types = options?.types.map((t) => t.value) ?? [];
  const retention = options?.retention ?? { min: 1, max: Number.MAX_SAFE_INTEGER };
  return z.object({
    application_id: z.coerce.number({ invalid_type_error: "required_application" }).min(1, "required_application"),
    storage_destination_id: z.coerce
      .number({ invalid_type_error: "required_destination" })
      .min(1, "required_destination"),
    type: z.string().refine((value) => types.includes(value), "required_type"),
    retention_count: z.coerce
      .number({ invalid_type_error: "retentionRange" })
      .int("retentionRange")
      .min(retention.min, "retentionRange")
      .max(retention.max, "retentionRange"),
    // Only what the API offered; without options nothing is accepted.
    frequency: z.string().refine((value) => frequencies.includes(value), "required_frequency"),
    // `date_format:H:i` on the backend; guards values that bypass the time input.
    schedule_time: z
      .string()
      .regex(/^([01]\d|2[0-3]):[0-5]\d$/, "scheduleTime")
      .default(BACKUP_DEFAULT_TIME),
    enabled: z.boolean().default(true),
    // Relative to the site folder; `..` and absolute paths point outside it.
    file_excludes: z
      .array(
        z
          .string()
          .max(255, "max255")
          .refine((v) => !v.trim().startsWith("/") && !/(^|\/)\.\.(\/|$)/.test(v.trim()), "excludeInsideSite"),
      )
      .max(100, "maxLines100")
      .default([]),
    database_excludes: z.array(z.string().max(64, "max64")).max(100, "maxLines100").default([]),
  });
}

// `confirm` must equal the domain (backend trims both); checked at the call site.
export const restoreFormSchema = z.object({
  type: z.enum(BACKUP_TYPES),
  confirm: z.string().min(1, "required_confirm"),
});
