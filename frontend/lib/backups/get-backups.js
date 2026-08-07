import { cache } from "react";
import { read } from "@/lib/api/read";
import { getApplications } from "@/lib/applications/get-applications";
import {
  BACKUP_PERIODS,
  RESTORE_IN_FLIGHT,
  backupTargetResponseSchema,
  backupTargetsResponseSchema,
  backupsResponseSchema,
  restoresResponseSchema,
} from "@/lib/schemas/backup";

/**
 * Backup history across every application, paginated by the server.
 *
 * Server-side paging rather than fetching the lot: this table grows without
 * bound — every application multiplied by every run it has ever made — which
 * is the conventions doc's own test for tier 3, whatever its examples say.
 *
 * The rows carry `application_id` and nothing else. Both backend controllers
 * eager-load `application:id,name,domain`, but neither resource outputs it, so
 * the name has to be joined here. Delete `withApplications` the day
 * `BackupResource` exposes it.
 */
export async function getBackups(searchParams = {}) {
  const [result, { applications }] = await Promise.all([
    read("/backups", backupsResponseSchema, {
      searchParams: {
        page: searchParams.page,
        per_page: searchParams.per_page,
        "filter[application_id]": searchParams.application,
        "filter[status]": searchParams.status,
        "filter[type]": searchParams.type,
        "filter[from]": since(searchParams.period),
      },
    }),
    getApplications(),
  ]);

  return {
    backups: withApplications(result.data?.backups ?? [], applications),
    meta: result.data?.meta ?? { current_page: 1, per_page: 20, total: 0, last_page: 1 },
    failed: result.failed,
  };
}

/**
 * A `?period=7` style filter turned into the date the API wants.
 *
 * Computed here rather than in the browser on purpose: the cutoff is compared
 * against timestamps the server wrote, and a browser six hours behind would
 * silently ask for a different day than the one the user picked.
 *
 * Returns undefined for anything unrecognised, which drops the filter — the
 * API validates its query and answers 422 for junk, and "there are no backups"
 * is an alarming thing to say to someone who mistyped a URL.
 */
export function since(period) {
  // Only the values the picker actually offers. An arbitrary number looked
  // harmless until `?period=999999` walked the date back past year zero, where
  // `toISOString` switches to an expanded-year format the API cannot parse —
  // and the screen answered "no backups" for a filter that should have
  // matched every one of them.
  if (!BACKUP_PERIODS.includes(String(period))) return undefined;

  const from = new Date();
  from.setDate(from.getDate() - Number(period));
  return from.toISOString().slice(0, 10);
}

/**
 * How many backups are complete, failed, or in flight.
 *
 * The list endpoint returns no per-status counts, so these are three throwaway
 * requests asking for a single row each and reading `meta.total`. Counting the
 * rows on the current page instead would report "3 failed" when it means "3
 * failed on page 1 of 40" — a number that is worse than none.
 *
 * The same application filter is applied, so the counts describe what the user
 * is actually looking at.
 */
export async function getBackupCounts(searchParams = {}) {
  const shared = {
    per_page: 1,
    "filter[application_id]": searchParams.application,
    "filter[type]": searchParams.type,
    // Same window as the rows. Counts describing a wider set than the table
    // shows is worse than no counts.
    "filter[from]": since(searchParams.period),
  };

  const [all, verified, failed, running] = await Promise.all(
    [undefined, "verified", "failed", "running"].map((status) =>
      read("/backups", backupsResponseSchema, {
        searchParams: { ...shared, "filter[status]": status },
      }),
    ),
  );

  return {
    total: all.data?.meta?.total ?? 0,
    verified: verified.data?.meta?.total ?? 0,
    failed: failed.data?.meta?.total ?? 0,
    running: running.data?.meta?.total ?? 0,
  };
}

export async function getRestores(searchParams = {}) {
  const [result, { applications }] = await Promise.all([
    read("/restores", restoresResponseSchema, {
      searchParams: { page: searchParams.page, per_page: searchParams.per_page },
    }),
    getApplications(),
  ]);

  return {
    restores: withApplications(result.data?.restores ?? [], applications),
    meta: result.data?.meta ?? { current_page: 1, per_page: 20, total: 0, last_page: 1 },
    failed: result.failed,
  };
}

/**
 * The restore currently rewriting THIS site, or null.
 *
 * `/restores` takes no application filter, so the newest page is read and
 * narrowed here. Only one restore can run per application — the backend
 * refuses a second with a 422 — so the first match is the match.
 *
 * The application page needs this for the same reason the server-level screen
 * does: a restore started here must show its progress and, when it lands, its
 * undo. Without it, pressing Restore on a site page looks like nothing
 * happened while the site is being overwritten.
 */
export async function getActiveRestore(applicationId) {
  const { restores } = await getRestores({ per_page: 20 });
  const id = Number(applicationId);

  return (
    restores.find(
      (restore) =>
        Number(restore.application_id) === id && RESTORE_IN_FLIGHT.includes(restore.status),
    ) ?? null
  );
}

/**
 * One application's backup settings, or null when nobody has configured it.
 *
 * Cached per request: the application Backups page reads it for the form, and
 * the status strip above the form reads it again.
 */
export const getBackupTarget = cache(async function getBackupTarget(applicationId) {
  const result = await read(`/applications/${applicationId}/backup-target`, backupTargetResponseSchema);
  return { target: result.data?.backup_target ?? null, failed: result.failed };
});

/**
 * Which sites are protected and which are not — the question the Backups
 * screen exists to answer.
 *
 * ONE call. This used to be one request per application because the endpoint
 * did not exist; `GET /backup-targets` now returns every site with its target
 * AND its most recent backup, so the screen can say whether the last run
 * actually succeeded rather than only when it was attempted.
 *
 * The applications list is still read — cached, so effectively free — for the
 * two things the overview resource does not carry: `is_staging` and the site
 * type, which the setup dialog's picker also needs.
 */
export async function getBackupCoverage() {
  const [result, { applications }] = await Promise.all([
    read("/backup-targets", backupTargetsResponseSchema),
    getApplications(),
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
    state: classify(entry.backup_target),
  }));

  const meta = result.data?.meta;

  return {
    rows,
    // Counted here rather than trusted from `meta`: the backend counts a site
    // with any target as protected, but a target that is switched off or set
    // to manual backs nothing up, and this screen must not call that covered.
    protected: rows.filter((row) => row.state === "protected").length,
    unprotected: rows.filter((row) => row.state === "unprotected").length,
    paused: rows.filter((row) => row.state === "paused").length,
    total: meta?.total ?? rows.length,
    failed: result.failed,
  };
}

/**
 * Three states, not two. A target that exists but is switched off — or set to
 * manual, which runs on no schedule at all — is the dangerous middle: it looks
 * configured on every screen that only asks "is there a target?", and backs up
 * nothing. It gets its own state so the UI can say so.
 */
function classify(target) {
  if (!target) return "unprotected";
  if (!target.enabled || target.frequency === "manual") return "paused";
  return "protected";
}

function withApplications(rows, applications) {
  const byId = new Map(applications.map((application) => [application.id, application]));
  return rows.map((row) => {
    const application = byId.get(row.application_id) ?? null;
    return {
      ...row,
      application_name: application?.name ?? null,
      // The restore confirmation compares against this exact string, so a row
      // without it cannot offer Restore at all.
      application_domain: application?.domain ?? null,
    };
  });
}
