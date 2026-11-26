import { z } from "zod";

export const BACKUP_PERIODS = ["7", "30", "90"];

export const BACKUP_TYPES = ["full", "filesystem", "database"];

export const BACKUP_DEFAULT_TIME = "02:00";

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
  types: z.array(z.object({ value: z.string(), label: z.string(), family: z.enum(["hosted", "container"]).nullish() })),
  retention: z.object({ min: z.number(), max: z.number() }),
  timezone: z.string().nullish(),
});

export function restorableTypes(backupType, hasDatabase = null) {
  if (backupType === "full") return hasDatabase === false ? ["filesystem"] : ["full", "filesystem", "database"];
  if (backupType === "filesystem") return ["filesystem"];
  if (backupType === "database") return ["database"];
  return [];
}

export const BACKUP_SUCCEEDED = "verified";

export const RESTORABLE_STATUS = BACKUP_SUCCEEDED;

export function backupHasArchive(status) {
  return status === BACKUP_SUCCEEDED;
}

export const BACKUP_IN_FLIGHT = ["pending", "running", "verifying"];
export const RESTORE_IN_FLIGHT = ["pending", "running"];

export const RESTORE_STATUSES = ["pending", "running", "succeeded", "failed"];

export const BACKUP_STATUSES = ["verified", "verifying", "running", "pending", "failed"];

export const backupSchema = z
  .object({
    id: z.number(),
    uid: z.string().nullish(),
    application_id: z.number().nullish(),
    type: z.string(),
    type_title: z.string().nullish(),
    has_database: z.boolean().nullish(),
    is_safety: z.boolean().default(false),
    status: z.string(),
    status_title: z.string().nullish(),
    reason: z.string().nullish(),
    reason_title: z.string().nullish(),
    error_class: z.string().nullish(),
    error_class_title: z.string().nullish(),
    is_stale: z.boolean().nullish(),
    clearable: z.boolean().nullish(),
    size_bytes: z.number().nullish(),
    storage_destination_name: z.string().nullish(),
    application_name: z.string().nullish(),
    application_domain: z.string().nullish(),
    database_id: z.number().nullish(),
    database_name: z.string().nullish(),
    database_engine: z.string().nullish(),
    site_name: z.string().nullish(),
    archived: z.boolean().default(false),
    reference: z.string().nullish(),
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

export const backupResponseSchema = z.object({ backup: backupSchema });

export const backupsResponseSchema = z.object({
  backups: z.array(backupSchema).default([]),
  meta: metaSchema.default({ current_page: 1, per_page: 20, total: 0, last_page: 1 }),
});

export const backupTargetSchema = z
  .object({
    id: z.number(),
    application_id: z.number().nullish(),
    database_id: z.number().nullish(),
    storage_destination_id: z.number(),
    storage_destination_name: z.string().nullish(),
    type: z.string(),
    type_title: z.string().nullish(),
    retention_count: z.number(),
    frequency: z.string(),
    frequency_title: z.string().nullish(),
    schedule_time: z.string().nullish(),
    enabled: z.boolean().default(true),
    file_excludes: z.array(z.string()).default([]),
    database_excludes: z.array(z.string()).default([]),
    last_run_at: z.string().nullish(),
    last_run_at_human: z.string().nullish(),
    last_backup_at: z.string().nullish(),
    last_backup_at_human: z.string().nullish(),
    next_run_at: z.string().nullish(),
    next_run_at_human: z.string().nullish(),
    // Backups use the app timezone, not the server clock like cron jobs.
    timezone: z.string().nullish(),
    is_due: z.boolean().nullish(),
    created_at: z.string().nullish(),
    updated_at: z.string().nullish(),
  })
  .passthrough();

export const COVERAGE_STATES = ["failing", "protected", "paused", "unprotected"];

export const applicationBackupSchema = z
  .object({
    application_id: z.number(),
    application_name: z.string(),
    application_domain: z.string(),
    backup_target: backupTargetSchema.nullable(),
    last_backup: backupSchema.nullable(),
    state: z.enum(COVERAGE_STATES).optional(),
  })
  .passthrough();

export const backupTargetsResponseSchema = z.object({
  backup_targets: z.array(applicationBackupSchema).default([]),
  meta: z
    .object({
      total: z.number(),
      protected: z.number(),
      unprotected: z.number(),
      paused: z.number().default(0),
      failing: z.number().default(0),
      configured: z.number().default(0),
      matched: z.number().default(0),
      current_page: z.number().default(1),
      per_page: z.number().default(10),
      last_page: z.number().default(1),
    })
    .default({
      total: 0,
      protected: 0,
      unprotected: 0,
      paused: 0,
      failing: 0,
      configured: 0,
      matched: 0,
      current_page: 1,
      per_page: 10,
      last_page: 1,
    }),
});

// Must be declared or Zod strips it.
export const databaseBackupTargetsResponseSchema = z.object({
  backup_targets: z
    .array(
      z
        .object({
          database_id: z.number(),
          database_name: z.string(),
          database_engine: z.string().nullish(),
          backup_target: backupTargetSchema.nullable(),
          last_backup: backupSchema.nullable(),
        })
        .passthrough(),
    )
    .default([]),
  meta: z.object({ total: z.number().default(0) }).passthrough().default({ total: 0 }),
});

export const backupTargetResponseSchema = z.object({
  backup_target: backupTargetSchema.nullable(),
  has_volumes: z.boolean().nullable().default(null),
});

export const databaseBackupTargetResponseSchema = z.object({
  backup_target: backupTargetSchema.nullable().default(null),
  backed_up_with: z
    .object({ application_id: z.number(), application_name: z.string().nullish() })
    .nullable()
    .default(null),
});

export const restoreSchema = z
  .object({
    id: z.number(),
    backup_id: z.number().nullish(),
    application_id: z.number().nullish(),
    application_name: z.string().nullish(),
    application_domain: z.string().nullish(),
    database_id: z.number().nullish(),
    database_name: z.string().nullish(),
    database_engine: z.string().nullish(),
    type: z.string(),
    type_title: z.string().nullish(),
    status: z.string(),
    status_title: z.string().nullish(),
    current_step: z.string().nullish(),
    current_step_title: z.string().nullish(),
    step_number: z.number().nullish(),
    total_steps: z.number().nullish(),
    reason: z.string().nullish(),
    reason_title: z.string().nullish(),
    safety_backup_id: z.number().nullish(),
    safety_copy: z.boolean().nullish(),
    rollback_path: z.string().nullish(),
    reference: z.string().nullish(),
    started_at: z.string().nullish(),
    started_at_human: z.string().nullish(),
    finished_at: z.string().nullish(),
    finished_at_human: z.string().nullish(),
  })
  .passthrough();

export const restoresResponseSchema = z.object({
  restores: z.array(restoreSchema).default([]),
  meta: metaSchema.default({ current_page: 1, per_page: 20, total: 0, last_page: 1 }),
});

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
    frequency: z.string().refine((value) => frequencies.includes(value), "required_frequency"),
    schedule_time: z
      .string()
      .regex(/^([01]\d|2[0-3]):[0-5]\d$/, "scheduleTime")
      .default(BACKUP_DEFAULT_TIME),
    enabled: z.boolean().default(true),
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

