import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getBackupCoverage, getBackupTargetOptions, getDatabaseBackupCoverage } from "@/lib/backups/get-backups";
import { DatabaseCoverageCard } from "@/components/backups/database-coverage-card";
import { getStorageDestinations } from "@/lib/storage/get-storage";
import { getDatabaseCounts } from "@/lib/databases/get-databases";
import { getSiteTypes } from "@/lib/applications/get-applications";
import { CoverageCard } from "@/components/backups/coverage-card";
import { BackupsEmptyState } from "@/components/backups/backups-empty-state";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

export default async function BackupsPage() {
  const [coverage, { destinations }, databases, appPermissions, t, { options: backupOptions }, { siteTypes }, databaseCoverage, permissions] = await Promise.all([
    getBackupCoverage(),
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
  ]);
  // Databases that are not part of an application have schedules of their own (RC-23).
  const databasesCard = (
    <DatabaseCoverageCard
      coverage={databaseCoverage}
      options={backupOptions}
      canOpenDatabases={can(permissions, "database", "view")}
    />
  );

  if (coverage.failed) return <LoadFailed description={t("loadFailed")} status={coverage.status} failure={coverage.failure} message={coverage.message} debug={coverage.debug} />;

  const canManage = can(appPermissions, "app_backup", "manage", "application");

  // Nothing configured: show an introduction instead of a list of red rows.
  const nothingConfigured = coverage.rows.every((row) => row.state === "unprotected");

  if (nothingConfigured && coverage.total > 0) {
    return (
      <div className="space-y-6">
      <BackupsEmptyState
        applications={coverage.rows.map((row) => row.application)}
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
    <div className="space-y-6">
    <CoverageCard
      coverage={coverage}
      applications={coverage.rows.map((row) => row.application)}
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
