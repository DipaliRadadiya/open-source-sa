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
  backupsResponseSchema,
  restoresResponseSchema,
} from "@/lib/schemas/backup";

// How long a finished restore keeps its banner (and its Undo) across reloads.
const FINISHED_RESTORE_WINDOW_MS = 24 * 60 * 60 * 1000;

// Held to the selector's values (the API 422s above 100). An absent value must
// still resolve to a number: the backend defaults to 20, `PerPageSelect` shows 10.
function perPage(value) {
  if (typeof value === "number") return value;
  if (value === undefined || value === null) return PER_PAGE_OPTIONS[0];
  return PER_PAGE_OPTIONS.includes(Number(value)) ? Number(value) : PER_PAGE_OPTIONS[0];
}

// Paginated by the server: the table grows without bound.
export async function getBackups(searchParams = {}) {
  return listBackups("/backups", searchParams);
}

// One application's history under `app_backup`; the server-wide list needs `backup`.
export async function getApplicationBackups(applicationId, searchParams = {}) {
  return listBackups(`/applications/${applicationId}/backups`, searchParams);
}

async function listBackups(path, searchParams) {
  const result = await read(path, backupsResponseSchema, {
    searchParams: {
        page: searchParams.page,
        per_page: perPage(searchParams.per_page),
        "filter[application_id]": searchParams.application,
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

// Computed on the server so the cutoff matches server timestamps. Undefined for
// anything unrecognised, which drops the filter instead of a 422.
export function since(period) {
  // Only the picker's values: a huge number goes past year zero, where
  // `toISOString` emits an expanded-year format the API cannot parse.
  if (!BACKUP_PERIODS.includes(String(period))) return undefined;

  const from = new Date();
  from.setDate(from.getDate() - Number(period));
  return from.toISOString().slice(0, 10);
}

/** Drops an unknown enum filter value instead of letting the API answer 422. */
function oneOf(value, allowed) {
  return allowed.includes(String(value)) ? String(value) : undefined;
}

// From `meta.counts`, filtered the same way as the rows.
export function backupCounts(meta) {
  const counts = meta?.counts ?? {};
  return {
    total: counts.total ?? 0,
    verified: counts.completed ?? 0,
    failed: counts.failed ?? 0,
    // Pending, running and verifying all count as "not finished yet".
    running: (counts.pending ?? 0) + (counts.running ?? 0) + (counts.verifying ?? 0),
  };
}

/** Every restore this server has run, filtered by the server. */
export async function getRestores(searchParams = {}) {
  const result = await read("/restores", restoresResponseSchema, {
    searchParams: {
      page: searchParams.page,
      per_page: perPage(searchParams.per_page),
      "filter[application_id]": searchParams.application,
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

// Filtered to the site by the API; the in-flight check is local because
// `filter[status]` takes one value. The backend 422s a second restore per app.
export async function getActiveRestore(applicationId, { dismissed = [] } = {}) {
  const { restores } = await getRestores({ application: applicationId, per_page: 5 });
  const active = restores.find((restore) => RESTORE_IN_FLIGHT.includes(restore.status)) ?? null;

  // Otherwise the newest one finished within the last day and not dismissed,
  // so its banner and Undo survive a reload.
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

  // Whether this run is an undo: only the backup says so (`is_safety`), and
  // without it an undo would end by offering to undo itself.
  const { data } = await read(`/backups/${recent.backup_id}`, backupResponseSchema);

  // A finished restore's safety copy may since have been pruned (only the
  // newest two are kept). Offering Undo for it would end in "not found".
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

// Null when not configured. Cached per request: the form and status strip both read it.
export const getBackupTarget = cache(async function getBackupTarget(applicationId) {
  const result = await read(`/applications/${applicationId}/backup-target`, backupTargetResponseSchema);
  return { target: result.data?.backup_target ?? null, failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
});

// Requests the API maximum of 100 rows: the counts need every row. Sites beyond
// 100 are missing until the backend offers a full count.
export async function getBackupCoverage() {
  const [result, { applications }] = await Promise.all([
    read("/backup-targets", backupTargetsResponseSchema, { searchParams: { per_page: 100 } }),
    getAllApplications(),
  ]);

  const byId = new Map(applications.map((application) => [application.id, application]));

  const rows = (result.data?.backup_targets ?? []).map((entry) => ({
    application: {
      id: entry.application_id,
      name: entry.application_name,
      domain: entry.application_domain,
      ...byId.get(entry.application_id),
    },
    target: entry.backup_target,
    lastBackup: entry.last_backup,
    state: classify(entry.backup_target, entry.last_backup),
  }));

  const meta = result.data?.meta;

  return {
    rows,
    // Counted here: the backend treats any target as protected, including
    // disabled or manual ones that back nothing up.
    protected: rows.filter((row) => row.state === "protected").length,
    unprotected: rows.filter((row) => row.state === "unprotected").length,
    paused: rows.filter((row) => row.state === "paused").length,
    failing: rows.filter((row) => row.state === "failing").length,
    total: meta?.total ?? rows.length,
    failed: result.failed,
    status: result.status,
    failure: result.failure, message: result.message, debug: result.debug,
  };
}

// Each application's backup standing for the Applications list, by id: one request for
// the whole list (the API maximum of 100 rows). `null` when it could not be read, so
// the column says nothing rather than "Not protected" for every row.
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

// `null` when unreadable: the form offers a retry, never a hard-coded list.
export const getBackupTargetOptions = cache(async function getBackupTargetOptions() {
  const result = await read("/backup-targets/options", backupTargetOptionsSchema);
  return { options: result.failed ? null : result.data, failed: result.failed };
});
