import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { backupCounts, getApplicationNewestBackups, getBackups, getDatabaseBackupCoverage } from "@/lib/backups/get-backups";
import { newestBackupIds } from "@/lib/backups/retry-eligibility";
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
  const [{ backups, meta, failed, status, failure, message }, { applications, failed: applicationsFailed }, permissions, appPermissions, t, database, coverage, siteNewest] = await Promise.all([
    getBackups({ ...sp, archived: false }),
    getAllApplications(),
    getPermissions(),
    getPermissions("application").catch(() => []),
    getTranslations("backups"),
    sp.database ? getDatabase(sp.database) : null,
    getDatabaseBackupCoverage(),
    getApplicationNewestBackups(),
  ]);

  if (failed && status === 422 && database?.status === 404) {
    const rest = new URLSearchParams(Object.entries(sp).filter(([key, v]) => key !== "database" && key !== "page" && typeof v === "string")).toString();
    redirect(rest ? `/backups/history?${rest}` : "/backups/history");
  }
  if (failed && status === 422) redirectUnknownApplication("/backups/history", sp, applications, applicationsFailed);
  if (failed) return <LoadFailed description={t("loadFailed")} status={status} failure={failure} message={message} />;

  const counts = backupCounts(meta);

  const canRestore = can(permissions, "backup", "manage");
  const canRun = can(appPermissions, "app_backup", "manage", "application");
  const hasFilters = Boolean(sp.application || sp.database || sp.status || sp.type || sp.period);
  redirectOutOfRange("/backups/history", sp, meta, failed);
  return (
    <NavTransitionProvider>
      <div className="space-y-4">
        <BackupsHistory
          backups={backups}
          counts={counts}
          applications={applications}
          database={sp.database ? { id: String(sp.database), name: database?.data?.name ?? `#${sp.database}` } : null}
          newest={Object.fromEntries(newestBackupIds({ applications: siteNewest, databases: coverage.rows }))}
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
