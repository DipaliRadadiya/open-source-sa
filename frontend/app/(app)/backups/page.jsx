import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getBackupCoverage } from "@/lib/backups/get-backups";
import { getStorageDestinations } from "@/lib/storage/get-storage";
import { CoverageCard } from "@/components/backups/coverage-card";
import { BackupsEmptyState } from "@/components/backups/backups-empty-state";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

export default async function BackupsPage() {
  const [coverage, { destinations }, appPermissions, t] = await Promise.all([
    getBackupCoverage(),
    getStorageDestinations(),
    // Application-level catalog with no site: answers "may this user configure
    // backups at all", which is what the Set up button needs. Per-site
    // filtering happens on the application's own page.
    getPermissions("application").catch(() => []),
    getTranslations("backups"),
  ]);

  if (coverage.failed) return <LoadFailed description={t("loadFailed")} />;

  const canManage = can(appPermissions, "app_backup", "manage", "application");

  // Nothing configured anywhere: the coverage card would be a list of red
  // rows with no explanation of what a backup here even is. Teach first.
  const nothingConfigured = coverage.rows.every((row) => row.state === "unprotected");

  if (nothingConfigured && coverage.total > 0) {
    return (
      <BackupsEmptyState
        applications={coverage.rows.map((row) => row.application)}
        destinations={destinations}
        canManage={canManage}
      />
    );
  }

  return (
    <CoverageCard
      coverage={coverage}
      applications={coverage.rows.map((row) => row.application)}
      destinations={destinations}
      canManage={canManage}
    />
  );
}
