import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getBackupCoverage, getBackupDestinationUsage, getBackupTargetOptions, getDatabaseBackupCoverage } from "@/lib/backups/get-backups";
import { DatabaseCoverageCard } from "@/components/backups/database-coverage-card";
import { getStorageDestinations } from "@/lib/storage/get-storage";
import { getDatabaseCounts } from "@/lib/databases/get-databases";
import { getSiteTypes } from "@/lib/applications/get-applications";
import { CoverageCard } from "@/components/backups/coverage-card";
import { BackupsEmptyState } from "@/components/backups/backups-empty-state";
import { LoadFailed } from "@/components/data-table/load-failed";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { redirectOutOfRange } from "@/lib/tables/redirect-out-of-range";

export const dynamic = "force-dynamic";

export default async function BackupsPage({ searchParams }) {
  const sp = await searchParams;
  const [coverage, { destinations }, databases, appPermissions, t, { options: backupOptions }, { siteTypes }, databaseCoverage, permissions, destinationUsage] = await Promise.all([
    getBackupCoverage(sp),
    getStorageDestinations(),
    getDatabaseCounts(),
    getPermissions("application").catch(() => []),
    getTranslations("backups"),
    getBackupTargetOptions(),
    getSiteTypes().catch(() => ({ siteTypes: [] })),
    getDatabaseBackupCoverage(),
    getPermissions(),
    getBackupDestinationUsage(),
  ]);
  const databasesCard = (
    <DatabaseCoverageCard
      coverage={databaseCoverage}
      options={backupOptions}
      canOpenDatabases={can(permissions, "database", "view")}
      canManage={can(permissions, "database", "manage")}
    />
  );

  if (coverage.failed) return <LoadFailed description={t("loadFailed")} status={coverage.status} failure={coverage.failure} message={coverage.message} debug={coverage.debug} />;
  redirectOutOfRange("/backups", sp, coverage.meta, coverage.failed);

  const canManage = can(appPermissions, "app_backup", "manage", "application");

  const nothingConfigured = coverage.configured === 0;

  if (nothingConfigured && coverage.total > 0) {
    return (
      <div className="space-y-6">
      <BackupsEmptyState
        applications={coverage.applications}
        destinations={destinations}
        canManage={canManage}
        databaseCounts={databases.counts}
        databasesKnown={databases.known}
        siteTypes={siteTypes}
        backupOptions={backupOptions}
      />
      {databasesCard}
      </div>
    );
  }

  return (
    <NavTransitionProvider>
    <div className="space-y-6">
    <CoverageCard
      coverage={coverage}
      applications={coverage.applications}
      destinationUsage={destinationUsage}
      pager={<DataTablePagination meta={coverage.meta} />}
      destinations={destinations}
      canManage={canManage}
      databaseCounts={databases.counts}
      databasesKnown={databases.known}
      siteTypes={siteTypes}
      backupOptions={backupOptions}
    />
    {databasesCard}
    </div>
    </NavTransitionProvider>
  );
}
