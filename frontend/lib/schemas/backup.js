import { z } from "zod";

/**
 * What a backup holds, and what a restore can put back.
 *
 * The same three values do double duty: on a target they mean "capture this",
 * on a restore they mean "put this back". The restore side is constrained —
 * you cannot ask for a database out of an archive that never had one — which
 * `restorableTypes` below works out so the user is never rejected *after*
 * typing the domain to confirm.
 */
/** The windows the History filter offers, and the only ones it honours. */
export const BACKUP_PERIODS = ["7", "30", "90"];

export const BACKUP_TYPES = ["full", "filesystem", "database"];

/**
 * The hour the backend falls back to when no time is stored.
 *
 * A copy of `BackupTarget::CRON`'s own `0 2 * * *`. `BackupTargetResource` now
 * returns `schedule_time`, so this is no longer papering over a missing field —
 * it is only what the form shows for a target that has genuinely never set one,
 * where the API correctly sends `null`.
 */
export const BACKUP_DEFAULT_TIME = "02:00";

/** Manual is not a schedule; the UI has to say so out loud. */
export const BACKUP_FREQUENCIES = ["manual", "daily", "weekly", "monthly"];

/**
 * Which types a given archive can satisfy.
 *
 * Mirrors `RestoreBackupRequest::withValidator()` exactly: what is asked for
 * must be a subset of what the archive holds. Duplicated here on purpose —
 * the alternative is letting someone choose "database", type their domain, and
 * only then be told no, which is the worst possible moment to be refused.
 */
export function restorableTypes(backupType) {
  if (backupType === "full") return ["full", "filesystem", "database"];
  if (backupType === "filesystem") return ["filesystem"];
  if (backupType === "database") return ["database"];
  return [];
}

/** Only a verified backup can be restored — the first guard in the request. */
export const RESTORABLE_STATUS = "verified";

/** Statuses that mean a run is still in flight, so a second must not start. */
export const BACKUP_IN_FLIGHT = ["pending", "running", "verifying"];
export const RESTORE_IN_FLIGHT = ["pending", "running"];

/** `RestoreStatus` on the backend, and what `filter[status]` will accept. */
export const RESTORE_STATUSES = ["pending", "running", "succeeded", "failed"];

export const backupSchema = z
  .object({
    id: z.number(),
    application_id: z.number().nullish(),
    type: z.string(),
    type_title: z.string().nullish(),
    // Taken automatically just before a restore overwrote the site, and exempt
    // from retention. It is the row someone hunts for after a bad restore, so
    // the list marks it rather than leaving it looking like any other backup.
    is_safety: z.boolean().default(false),
    status: z.string(),
    status_title: z.string().nullish(),
    // A stable key naming the step that failed; `reason_title` is the same
    // thing in the reader's language. Branch on `reason`, display the title.
    reason: z.string().nullish(),
    reason_title: z.string().nullish(),
    size_bytes: z.number().nullish(),
    reference: z.string().nullish(),
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
  // Undeclared, Zod stripped these and the summary row silently read zero.
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

export const backupsResponseSchema = z.object({
  backups: z.array(backupSchema).default([]),
  meta: metaSchema.default({ current_page: 1, per_page: 20, total: 0, last_page: 1 }),
});

export const backupTargetSchema = z
  .object({
    id: z.number(),
    application_id: z.number(),
    storage_destination_id: z.number(),
    // Only present when the controller eager-loads the relation, which
    // `showTarget` does and `saveTarget` does on the way back out.
    storage_destination_name: z.string().nullish(),
    type: z.string(),
    type_title: z.string().nullish(),
    retention_count: z.number(),
    frequency: z.string(),
    frequency_title: z.string().nullish(),
    // "HH:MM" in the server's timezone. Nullish because a target that has never
    // set a time stores none — not because the API withholds it.
    schedule_time: z.string().nullish(),
    enabled: z.boolean().default(true),
    file_excludes: z.array(z.string()).default([]),
    database_excludes: z.array(z.string()).default([]),
    last_run_at: z.string().nullish(),
    last_run_at_human: z.string().nullish(),
    // Sent by the backend since 2026-08-07. Never recompute these from their
    // cron constants — a frontend copy of a server-side schedule drifts the
    // first time someone changes it.
    next_run_at: z.string().nullish(),
    next_run_at_human: z.string().nullish(),
    is_due: z.boolean().nullish(),
    created_at: z.string().nullish(),
    updated_at: z.string().nullish(),
  })
  .passthrough();

/**
 * One row of the cross-application overview: a site, whether it is configured,
 * and how its last backup went.
 *
 * `backup_target` is nested rather than flattened on purpose — on an
 * unprotected site every target field would be null, and a caller could not
 * tell "not configured" from "configured with nothing set".
 */
export const applicationBackupSchema = z
  .object({
    application_id: z.number(),
    application_name: z.string(),
    application_domain: z.string(),
    backup_target: backupTargetSchema.nullable(),
    last_backup: backupSchema.nullable(),
  })
  .passthrough();

export const backupTargetsResponseSchema = z.object({
  backup_targets: z.array(applicationBackupSchema).default([]),
  meta: z
    .object({ total: z.number(), protected: z.number(), unprotected: z.number() })
    .default({ total: 0, protected: 0, unprotected: 0 }),
});

/** `backup_target` is null for a site nobody has configured yet. */
export const backupTargetResponseSchema = z.object({
  backup_target: backupTargetSchema.nullable(),
});

export const restoreSchema = z
  .object({
    id: z.number(),
    backup_id: z.number(),
    application_id: z.number().nullish(),
    // Carried on the row now, so the table names the site without a second
    // request. Null when the site has since been deleted.
    application_name: z.string().nullish(),
    application_domain: z.string().nullish(),
    type: z.string(),
    type_title: z.string().nullish(),
    status: z.string(),
    status_title: z.string().nullish(),
    current_step: z.string().nullish(),
    current_step_title: z.string().nullish(),
    // Position in the sequence. Present so a progress bar can be drawn without
    // the frontend keeping its own copy of the step list — that list is
    // config on the backend and would drift the first time it changed.
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

/**
 * The settings form, mirroring `SaveBackupTargetRequest`.
 *
 * `retention_count` has a floor of 1 on the backend for a reason worth
 * repeating in the UI: zero would prune the backup the run had just taken, so
 * "keep 0" reads as "backups silently do nothing".
 */
export const backupTargetFormSchema = z.object({
  application_id: z.coerce.number({ invalid_type_error: "required_application" }).min(1, "required_application"),
  storage_destination_id: z.coerce
    .number({ invalid_type_error: "required_destination" })
    .min(1, "required_destination"),
  type: z.enum(BACKUP_TYPES),
  retention_count: z.coerce
    .number({ invalid_type_error: "retentionRange" })
    .int("retentionRange")
    .min(1, "retentionRange")
    .max(365, "retentionRange"),
  frequency: z.enum(BACKUP_FREQUENCIES),
  // `date_format:H:i` on the backend. The browser's own time input already
  // refuses anything else, so this is the guard for a value that arrives some
  // other way rather than a message anyone should see.
  schedule_time: z
    .string()
    .regex(/^([01]\d|2[0-3]):[0-5]\d$/, "scheduleTime")
    .default(BACKUP_DEFAULT_TIME),
  enabled: z.boolean().default(true),
  file_excludes: z.array(z.string().max(255, "max255")).max(100, "max100").default([]),
  database_excludes: z.array(z.string().max(64, "max64")).max(100, "max100").default([]),
});

/**
 * The restore confirmation.
 *
 * `confirm` has to equal the application's domain exactly — the backend
 * compares them after trimming. Validated against the real domain at the call
 * site, because a schema cannot know which site is being restored.
 */
export const restoreFormSchema = z.object({
  type: z.enum(BACKUP_TYPES),
  confirm: z.string().min(1, "required_confirm"),
});
