import { cache } from "react";
import { read } from "@/lib/api/read";
import { parseApiWallClock } from "@/lib/format/api-date";
import { PER_PAGE_OPTIONS } from "@/lib/schemas/user";
import { classify } from "@/lib/backups/coverage-state";
import { getAllApplications } from "@/lib/applications/get-applications";
import {
  BACKUP_PERIODS,
  BACKUP_STATUSES,
  BACKUP_TYPES,
  RESTORE_IN_FLIGHT,
  RESTORE_STATUSES,
  backupResponseSchema,
  backupTargetOptionsSchema,
  backupTargetResponseSchema,
  backupTargetsResponseSchema,
  databaseBackupTargetResponseSchema,
  backupsResponseSchema,
  restoresResponseSchema,
  databaseBackupTargetsResponseSchema,
} from "@/lib/schemas/backup";
import { perPageFrom } from "@/lib/tables/remembered-per-page";

const FINISHED_RESTORE_WINDOW_MS = 24 * 60 * 60 * 1000;

// The API refuses per_page above 100.
async function perPage(value) {
  if (typeof value === "number") return value;
  return perPageFrom(value);
}

export async function getBackups(searchParams = {}) {
  return listBackups("/backups", searchParams);
}

export async function getDatabaseBackups(databaseId, searchParams = {}) {
  return listBackups(`/databases/${databaseId}/backups`, searchParams);
}

export async function getArchivedDatabaseBackups(engine) {
  return listBackups("/backups", { archived: true, engine, type: "database", per_page: 50 });
}

export async function getApplicationBackups(applicationId, searchParams = {}) {
  return listBackups(`/applications/${applicationId}/backups`, searchParams);
}

async function listBackups(path, searchParams) {
  const result = await read(path, backupsResponseSchema, {
    searchParams: {
        page: searchParams.page,
        per_page: await perPage(searchParams.per_page),
        "filter[application_id]": searchParams.application,
        "filter[database_id]": searchParams.database,
        "filter[archived]": searchParams.archived === true ? 1 : searchParams.archived === false ? 0 : undefined,
        "filter[engine]": searchParams.engine,
        "filter[status]": oneOf(searchParams.status, BACKUP_STATUSES),
        "filter[type]": oneOf(searchParams.type, BACKUP_TYPES),
        "filter[from]": since(searchParams.period),
      },
  });

  return {
    backups: result.data?.backups ?? [],
    meta: result.data?.meta ?? { current_page: 1, per_page: PER_PAGE_OPTIONS[0], total: 0, last_page: 1 },
    failed: result.failed,
    status: result.status,
    failure: result.failure, message: result.message, debug: result.debug,
  };
}

export function since(period) {
  // A huge period goes past year 0, where toISOString() makes a date the API can't parse.
  if (!BACKUP_PERIODS.includes(String(period))) return undefined;

  const from = new Date();
  from.setDate(from.getDate() - Number(period));
  return from.toISOString().slice(0, 10);
}

function oneOf(value, allowed) {
  return allowed.includes(String(value)) ? String(value) : undefined;
}

export function backupCounts(meta) {
  const counts = meta?.counts ?? {};
  return {
    total: counts.total ?? 0,
    verified: counts.completed ?? 0,
    failed: counts.failed ?? 0,
    running: (counts.pending ?? 0) + (counts.running ?? 0) + (counts.verifying ?? 0),
  };
}

export async function getRestores(searchParams = {}) {
  const result = await read("/restores", restoresResponseSchema, {
    searchParams: {
      page: searchParams.page,
      per_page: await perPage(searchParams.per_page),
      "filter[application_id]": searchParams.application,
      "filter[database_id]": searchParams.database,
      "filter[status]": oneOf(searchParams.status, RESTORE_STATUSES),
      "filter[type]": oneOf(searchParams.type, BACKUP_TYPES),
      "filter[from]": since(searchParams.period),
    },
  });

  return {
    restores: result.data?.restores ?? [],
    meta: result.data?.meta ?? { current_page: 1, per_page: PER_PAGE_OPTIONS[0], total: 0, last_page: 1 },
    failed: result.failed,
    status: result.status,
    failure: result.failure, message: result.message, debug: result.debug,
  };
}

export async function getActiveRestore(applicationId, { dismissed = [], database } = {}) {
  const { restores: rows } = await getRestores({ application: applicationId, database, per_page: 5 });
  const restores = applicationId || database ? rows : rows.filter((restore) => restore.application_id);
  const active = restores.find((restore) => RESTORE_IN_FLIGHT.includes(restore.status)) ?? null;

  const latest = restores[0] ?? null;
  const finishedAt = parseApiWallClock(latest?.finished_at);
  const recent =
    active ??
    (latest &&
    !dismissed.includes(latest.id) &&
    finishedAt &&
    Date.now() - finishedAt.getTime() < FINISHED_RESTORE_WINDOW_MS
      ? latest
      : null);

  if (recent === null) return null;

  const { data } = await read(`/backups/${recent.backup_id}`, backupResponseSchema);

  let safetyBackupId = recent.safety_backup_id ?? null;
  if (recent !== active && safetyBackupId) {
    const safety = await read(`/backups/${safetyBackupId}`, backupResponseSchema);
    if (!safety.data?.backup) safetyBackupId = null;
  }

  return {
    ...recent,
    safety_backup_id: safetyBackupId,
    restored_safety_copy: Boolean(data?.backup?.is_safety),
  };
}

export const getBackupTarget = cache(async function getBackupTarget(applicationId) {
  const result = await read(`/applications/${applicationId}/backup-target`, backupTargetResponseSchema);
  return { target: result.data?.backup_target ?? null, hasVolumes: result.data?.has_volumes ?? null, failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
});

export const getDatabaseBackupTarget = cache(async function getDatabaseBackupTarget(databaseId) {
  const result = await read(`/databases/${databaseId}/backup-target`, databaseBackupTargetResponseSchema);
  return {
    target: result.data?.backup_target ?? null,
    backedUpWith: result.data?.backed_up_with ?? null,
    failed: result.failed,
    status: result.status,
    failure: result.failure, message: result.message, debug: result.debug,
  };
});

export const COVERAGE_FILTERS = ["unprotected", "protected", "failing", "paused"];

function coverageStateParams(value) {
  if (!COVERAGE_FILTERS.includes(value)) return {};
  if (value === "unprotected") return { "filter[protected]": 0 };
  return { "filter[state]": value };
}

function coverageRow(entry, byId) {
  return {
    application: {
      id: entry.application_id,
      name: entry.application_name,
      domain: entry.application_domain,
      ...byId.get(entry.application_id),
    },
    target: entry.backup_target,
    lastBackup: entry.last_backup,
    state: entry.state ?? classify(entry.backup_target, entry.last_backup),
  };
}

export async function getBackupCoverage(searchParams = {}) {
  const [result, { applications }] = await Promise.all([
    read("/backup-targets", backupTargetsResponseSchema, {
      searchParams: {
        page: searchParams.page,
        per_page: await perPage(searchParams.per_page),
        search: searchParams.search || undefined,
        ...coverageStateParams(searchParams.state),
        "filter[type]": oneOf(searchParams.type, BACKUP_TYPES),
      },
    }),
    getAllApplications(),
  ]);

  const byId = new Map(applications.map((application) => [application.id, application]));
  const rows = (result.data?.backup_targets ?? []).map((entry) => coverageRow(entry, byId));
  const meta = result.data?.meta ?? backupTargetsResponseSchema.parse({}).meta;

  return {
    rows,
    applications,
    protected: meta.protected,
    unprotected: meta.unprotected,
    paused: meta.paused,
    failing: meta.failing,
    configured: meta.configured,
    total: meta.total,
    meta: { ...meta, total: meta.matched },
    failed: result.failed,
    status: result.status,
    failure: result.failure, message: result.message, debug: result.debug,
  };
}

export async function getBackupDestinationUsage() {
  const rows = [];
  for (let page = 1; page <= 50; page += 1) {
    const result = await read("/backup-targets", backupTargetsResponseSchema, {
      searchParams: { page, per_page: 100, "filter[state]": "protected,paused,failing" },
    });
    if (result.failed) return { inUse: [], lastBackups: [], failed: true };
    rows.push(...(result.data?.backup_targets ?? []));
    if (page >= (result.data?.meta?.last_page ?? 1)) break;
  }
  return {
    inUse: [...new Set(rows.map((row) => row.backup_target?.storage_destination_id).filter(Boolean))],
    targets: rows.map((row) => row.backup_target?.storage_destination_id).filter(Boolean),
    lastBackups: rows
      .filter((row) => row.last_backup)
      .map((row) => ({ ...row.last_backup, storage_destination_id: row.backup_target?.storage_destination_id })),
    failed: false,
  };
}

export const getDatabaseBackupCoverage = cache(async function getDatabaseBackupCoverage() {
  const result = await read("/backup-targets", databaseBackupTargetsResponseSchema, {
    searchParams: { kind: "database", per_page: 100 },
  });
  const rows = (result.data?.backup_targets ?? []).map((entry) => ({
    database: { id: entry.database_id, name: entry.database_name, engine: entry.database_engine ?? null },
    target: entry.backup_target,
    lastBackup: entry.last_backup,
    state: classify(entry.backup_target, entry.last_backup),
  }));
  return { rows, total: result.data?.meta?.total ?? rows.length, failed: result.failed, status: result.status, failure: result.failure, message: result.message };
});

export const getBackupStanding = cache(async function getBackupStanding() {
  const result = await read("/backup-targets", backupTargetsResponseSchema, { searchParams: { per_page: 100 } });
  if (result.failed) return null;
  const byId = {};
  for (const entry of result.data?.backup_targets ?? []) {
    byId[entry.application_id] = {
      state: classify(entry.backup_target, entry.last_backup),
      when: entry.last_backup?.created_at_human ?? null,
      lastFailed: entry.last_backup?.status === "failed",
    };
  }
  return byId;
});

export const getApplicationNewestBackups = cache(async function getApplicationNewestBackups() {
  const result = await read("/backup-targets", backupTargetsResponseSchema, { searchParams: { per_page: 100 } });
  return result.failed ? [] : (result.data?.backup_targets ?? []);
});

export const getBackupTargetOptions = cache(async function getBackupTargetOptions() {
  const result = await read("/backup-targets/options", backupTargetOptionsSchema);
  return { options: result.failed ? null : result.data, failed: result.failed };
});
