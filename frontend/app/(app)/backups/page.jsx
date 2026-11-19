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
    // One page, searched and filtered by the server (RC-42).
    getBackupCoverage(sp),
    getStorageDestinations(),
    // Lets the setup form warn when the chosen site has no database.
    getDatabaseCounts(),
    // Application-level catalog (no site): whether the user may configure
    // backups at all. Per-site filtering happens on the application page.
    getPermissions("application").catch(() => []),
    getTranslations("backups"),
    // A failure is carried as null; it never blocks the coverage list.
    getBackupTargetOptions(),
    // Which site types never have a database, so their setup offers Files only.
    getSiteTypes().catch(() => ({ siteTypes: [] })),
    getDatabaseBackupCoverage(),
    getPermissions(),
    // Every configured site, for the storage warning: one page would miss destinations.
    getBackupDestinationUsage(),
  ]);
  // Databases that are not part of an application have schedules of their own (RC-23).
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

  // Nothing configured: show an introduction instead of a list of red rows. From the server's
  // count, not this page's rows, which a filter can empty.
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
