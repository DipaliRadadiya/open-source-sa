import { getTranslations, getFormatter } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import {
  getEngines,
  getDatabases,
  getUntracked,
  getConnections,
} from "@/lib/databases/get-databases";
import { getExports } from "@/lib/databases/get-exports";
import { getAllApplications, getPhpmyadminSite, getSiteTypes } from "@/lib/applications/get-applications";
import { getDatabaseCounts, getUnlinkedCount } from "@/lib/databases/get-databases";
import { formatBytes } from "@/lib/format/bytes";
import { parseApiDate } from "@/lib/format/api-date";
import { EngineBar } from "@/components/databases/engine-bar";
import { EngineState } from "@/components/databases/engine-state";
import { UntrackedBanner } from "@/components/databases/untracked-banner";
import { UnlinkedBanner } from "@/components/databases/unlinked-banner";
import { DatabasesTable } from "@/components/databases/databases-table";
import { LoadFailed } from "@/components/data-table/load-failed";
import { redirectOutOfRange } from "@/lib/tables/redirect-out-of-range";
import { PageHeader } from "@/components/ui/page-header";
import { PermissionDenied } from "@/components/sections/permission-denied";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("databases");
  return { title: t("title") };
}

export default async function DatabasesPage({ searchParams }) {
  const sp = await searchParams;
  // Serialised so React's `cache` sees a stable primitive argument.
  const query = new URLSearchParams(
    Object.entries(sp ?? {}).filter(([, v]) => typeof v === "string"),
  ).toString();
  const [permissions, t, format, live] = await Promise.all([
    getPermissions(),
    getTranslations("databases"),
    getFormatter(),
    getEngines(),
  ]);
  const { engines, failed, status, failure, message } = live;

  if (!can(permissions, "database", "view")) return <PermissionDenied title={t("title")} />;
  const canManage = can(permissions, "database", "manage");

  if (failed) return <LoadFailed description={t("loadFailed")} status={status} failure={failure} message={message} />;

  // Only a running engine can hold databases; skip the list otherwise.
  const usable = engines.some((engine) => engine.running);

  const [{ databases, meta: dbMeta, failed: dbFailed, status: dbStatus, failure: dbFailure, message: dbMessage }, untracked, connections, exportList, phpmyadmin, appList, dbCounts, unlinkedCount, catalogue] = await Promise.all([
    usable ? getDatabases(query) : Promise.resolve({ databases: [], failed: false }),
    usable && canManage ? getUntracked(engines) : Promise.resolve([]),
  // Needed most when nothing is reachable.
    canManage ? getConnections() : Promise.resolve([]),
  // For the "Last backup" column; one global request, not one per row.
    usable ? getExports() : Promise.resolve({ exports: [], failed: false }),
  // Whether this server has a phpMyAdmin to open at all.
    usable ? getPhpmyadminSite() : Promise.resolve(null),
  // The create dialog's site picker and which sites already have a database.
    usable && canManage ? getAllApplications() : Promise.resolve({ applications: [] }),
    usable && canManage ? getDatabaseCounts() : Promise.resolve({ counts: null, known: false }),
  // Server-wide, so it is unaffected by the table's search and paging.
    usable ? getUnlinkedCount() : Promise.resolve(0),
  // `cache`d; a failure only loses the greying, not the page.
    usable && canManage
      ? getSiteTypes().catch(() => ({ siteTypes: [] }))
      : Promise.resolve({ siteTypes: [] }),
  ]);

  if (dbFailed) return <LoadFailed description={t("loadFailed")} status={dbStatus} failure={dbFailure} message={dbMessage} />;

  // The newest restorable dump per database: completed and its file still on
  // disk. Compared by timestamp, not by endpoint order or id.
  const lastBackup = {};
  for (const row of exportList.exports) {
    if (row.status !== "completed" || row.available === false) continue;
    const at = parseApiDate(row.finished_at ?? row.created_at)?.getTime() ?? 0;
    const current = lastBackup[row.database_id];
    if (!current || at > current.at) lastBackup[row.database_id] = { ...row, at };
  }

  /*
   * The count is `meta.total` (the page holds at most ten). There is no
   * server-side size total, so the size is shown only when this page holds
   * every database; a partial sum must not pose as a total.
   */
  const totalBytes = databases.reduce(
    (sum, db) => sum + (Number(db.size_bytes) || 0),
    0,
  );
  const wholeList = databases.length === (dbMeta?.total ?? databases.length);
  const summary = databases.length
    ? [
        t("summary.count", { count: dbMeta?.total ?? databases.length }),
        wholeList ? formatBytes(totalBytes, format) : null,
      ]
        .filter(Boolean)
        .join(" · ")
    : null;


  // A page past the end redirects to the last real page instead of erroring.
  redirectOutOfRange("/databases", sp, dbMeta, dbFailed);
  return (
    <div className="space-y-6">
      <PageHeader title={t("title")} subtitle={t("subtitle")} />

      {usable ? (
        <div className="space-y-4">
          <EngineBar
            engines={engines}
            connections={connections}
            canManage={canManage}
            summary={summary}
          />
          <UntrackedBanner untracked={untracked} canManage={canManage} />
          {/* Below Adopt: an untracked database must be adopted before it can be linked. */}
          <UnlinkedBanner
            count={unlinkedCount}
            filtered={new URLSearchParams(query).get("attached") === "0"}
          />
          <DatabasesTable
            data={databases}
            meta={dbMeta}
            engines={engines}
            canManage={canManage}
            lastBackup={lastBackup}
            // A failed exports request must not read as "never backed up".
            backupsUnknown={exportList.failed}
            // Null when the lookup failed, which must not read as "none".
            phpmyadminSites={phpmyadmin.known ? phpmyadmin.sites : null}
            applications={appList.applications}
            databaseCounts={dbCounts.counts}
            databasesKnown={dbCounts.known}
            // Lets the site picker grey sites that cannot use the chosen engine.
            siteTypes={catalogue.siteTypes}
          />
        </div>
      ) : (
        // Nothing to connect to: the engine's state is the page.
        <EngineState
          engines={engines}
          connections={connections}
          canManage={canManage}
        />
      )}
    </div>
  );
}
