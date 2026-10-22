import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { backupCounts, getBackups, getDatabaseBackupCoverage } from "@/lib/backups/get-backups";
import { filterDatabases } from "@/lib/backups/filter-databases";
import { getAllApplications } from "@/lib/applications/get-applications";
import { getDatabase } from "@/lib/databases/get-database";
import { BackupsHistory } from "@/components/backups/backups-history";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { LoadFailed } from "@/components/data-table/load-failed";
import { redirectOutOfRange } from "@/lib/tables/redirect-out-of-range";
import { redirect } from "next/navigation";
import { redirectUnknownApplication } from "@/lib/tables/redirect-unknown-application";

export const dynamic = "force-dynamic";

export default async function BackupsHistoryPage({ searchParams }) {
  const sp = await searchParams;
  const [{ backups, meta, failed, status, failure, message }, { applications, failed: applicationsFailed }, permissions, appPermissions, t, database, coverage] = await Promise.all([
    // Archived backups have their own tab.
    getBackups({ ...sp, archived: false }),
    getAllApplications(),
    getPermissions(),
    getPermissions("application").catch(() => []),
    getTranslations("backups"),
    // "View all" on a database's Backups tab; named so the filter is visible.
    sp.database ? getDatabase(sp.database) : null,
    // Databases on their own, for the filter; a failed read only loses those entries.
    getDatabaseBackupCoverage(),
  ]);

  // Only a refusal (422) can be the filter; anything else is a real failure.
  // A deleted database's link: the API refuses the unknown id, so drop the filter.
  if (failed && status === 422 && database?.status === 404) {
    const rest = new URLSearchParams(Object.entries(sp).filter(([key, v]) => key !== "database" && key !== "page" && typeof v === "string")).toString();
    redirect(rest ? `/backups/history?${rest}` : "/backups/history");
  }
  if (failed && status === 422) redirectUnknownApplication("/backups/history", sp, applications, applicationsFailed);
  if (failed) return <LoadFailed description={t("loadFailed")} status={status} failure={failure} message={message} />;

  const counts = backupCounts(meta);

  // Restore overwrites a live site, so it needs `backup,manage`.
  const canRestore = can(permissions, "backup", "manage");
  // Re-running a failed backup is an app_backup action, not a restore.
  const canRun = can(appPermissions, "app_backup", "manage", "application");
  const hasFilters = Boolean(sp.application || sp.database || sp.status || sp.type || sp.period);
  // A page past the end redirects to the last real page instead of erroring.
  redirectOutOfRange("/backups/history", sp, meta, failed);
  return (
    <NavTransitionProvider>
      <div className="space-y-4">
        <BackupsHistory
          backups={backups}
          counts={counts}
          applications={applications}
          database={sp.database ? { id: String(sp.database), name: database?.data?.name ?? `#${sp.database}` } : null}
          databases={filterDatabases(coverage.rows, sp.database ? { id: sp.database, name: database?.data?.name } : null)}
          canRestore={canRestore}
          canRun={canRun}
          hasFilters={hasFilters}
          pager={<DataTablePagination meta={meta} />}
        />
      </div>
    </NavTransitionProvider>
  );
}
