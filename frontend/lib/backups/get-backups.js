import { cache } from "react";
import { read } from "@/lib/api/read";
import { PER_PAGE_OPTIONS } from "@/lib/schemas/user";
import { classify } from "@/lib/backups/coverage-state";
import { getAllApplications } from "@/lib/applications/get-applications";
import {
  BACKUP_PERIODS,
  BACKUP_STATUSES,
  BACKUP_TYPES,
  RESTORE_IN_FLIGHT,
  RESTORE_STATUSES,
  backupTargetResponseSchema,
  backupTargetsResponseSchema,
  backupsResponseSchema,
  restoresResponseSchema,
} from "@/lib/schemas/backup";

/**
 * `per_page` from the URL, held to the four values the selector offers.
 *
 * These two lists were the only paginated ones passing it through untouched,
 * and the API takes any integer 1-100. So `?per_page=999` answered 422 and the
 * whole page rendered its load-failure panel over one junk query param, while
 * `?per_page=7` was honoured — paging by seven under a rows-per-page box that
 * rendered blank, because seven is not one of its options.
 *
 * Only strings are policed. Those come from the URL and are somebody's typing;
 * a number came from our own code, which asks for `per_page: 5` when it wants
 * the few most recent restores rather than a screenful, and overriding that
 * would be this guard picking a fight with its own callers.
 */
function perPage(value) {
  if (typeof value === "number") return value;
  if (value === undefined || value === null) return undefined;
  return PER_PAGE_OPTIONS.includes(Number(value)) ? Number(value) : PER_PAGE_OPTIONS[0];
}

/**
 * Backup history across every application, paginated by the server.
 *
 * Server-side paging rather than fetching the lot: this table grows without
 * bound — every application multiplied by every run it has ever made — which
 * is the conventions doc's own test for tier 3, whatever its examples say.
 *
 * Rows carry `application_name` and `application_domain` directly now, so the
 * client-side join against the applications list is gone — and with it a
 * second request on every load of this screen.
 */
export async function getBackups(searchParams = {}) {
  const result = await read("/backups", backupsResponseSchema, {
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
    failure: result.failure,
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
 * The same treatment for the other enum filters.
 *
 * `period` was defended and `status`/`type` were not, so `?period=junk` quietly
 * dropped the filter while `?status=junk` earned a 422 and a full-page "we
 * could not load this". Both are the same mistake — a URL nobody typed
 * deliberately — and neither deserves to lose the reader the whole screen.
 */
function oneOf(value, allowed) {
  return allowed.includes(String(value)) ? String(value) : undefined;
}

/**
 * How many backups are complete, failed or in flight.
 *
 * Read straight off `meta.counts`, which the API now returns with the same
 * filters as the rows. This used to be four throwaway requests asking for one
 * row each — four round trips on every page load, to render one line.
 */
export function backupCounts(meta) {
  const counts = meta?.counts ?? {};
  return {
    total: counts.total ?? 0,
    verified: counts.completed ?? 0,
    failed: counts.failed ?? 0,
    // One number for "not finished yet": three separate tallies for pending,
    // running and verifying would be splitting a single idea three ways.
    running: (counts.pending ?? 0) + (counts.running ?? 0) + (counts.verifying ?? 0),
  };
}

/**
 * Every restore this server has run, filtered by the server.
 *
 * The same four filters as the backup history and the same query shape —
 * `/restores` validates `filter[application_id|status|type|from|to]` now,
 * where it used to accept only the first two and ignore the rest.
 */
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
    failure: result.failure,
  };
}

/**
 * The restore currently rewriting THIS site, or null.
 *
 * Narrowed to the site by the API rather than by reading the newest twenty
 * restores across every application and filtering them here — on a busy
 * server that page could be entirely other people's sites and this would
 * answer "nothing is running" while the site was being overwritten.
 *
 * The in-flight check stays local because `filter[status]` takes one value and
 * this needs two; over a single site's own restores that is a handful of rows.
 * Only one can run per application — the backend refuses a second with a 422 —
 * so the first match is the match.
 *
 * The application page needs this for the same reason the server-level screen
 * does: a restore started here must show its progress and, when it lands, its
 * undo. Without it, pressing Restore on a site page looks like nothing
 * happened while the site is being overwritten.
 */
export async function getActiveRestore(applicationId) {
  const { restores } = await getRestores({ application: applicationId, per_page: 5 });

  return restores.find((restore) => RESTORE_IN_FLIGHT.includes(restore.status)) ?? null;
}

/**
 * One application's backup settings, or null when nobody has configured it.
 *
 * Cached per request: the application Backups page reads it for the form, and
 * the status strip above the form reads it again.
 */
export const getBackupTarget = cache(async function getBackupTarget(applicationId) {
  const result = await read(`/applications/${applicationId}/backup-target`, backupTargetResponseSchema);
  return { target: result.data?.backup_target ?? null, failed: result.failed, status: result.status, failure: result.failure };
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
 *
 * Asks for every row rather than a page of them, at the API's maximum of 100.
 * This endpoint started paging at ten and the call carried no parameters, so
 * the screen was quietly answering "which of my first ten sites are exposed".
 *
 * It has to hold every row, not a page: the header counts protected sites
 * itself (see below), and a count built from one page would be wrong. On a
 * server with more than 100 sites the tail is missing, which needs the backend
 * ask below resolved rather than a bigger number.
 */
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
    // Counted here rather than trusted from `meta`: the backend counts a site
    // with any target as protected, but a target that is switched off or set
    // to manual backs nothing up, and this screen must not call that covered.
    //
    // Same reason `filter[protected]=0` is not used for the "Not protected"
    // toggle: it means "has no target row", so it would hide every paused site
    // — precisely the ones that look configured and are backing nothing up.
    protected: rows.filter((row) => row.state === "protected").length,
    unprotected: rows.filter((row) => row.state === "unprotected").length,
    paused: rows.filter((row) => row.state === "paused").length,
    failing: rows.filter((row) => row.state === "failing").length,
    total: meta?.total ?? rows.length,
    failed: result.failed,
    status: result.status,
    failure: result.failure,
  };
}



