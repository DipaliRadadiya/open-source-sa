import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { backupCounts, getBackups } from "@/lib/backups/get-backups";
import { getAllApplications } from "@/lib/applications/get-applications";
import { BackupsHistory } from "@/components/backups/backups-history";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { PageOutOfRange } from "@/components/data-table/page-out-of-range";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

export default async function BackupsHistoryPage({ searchParams }) {
  const sp = await searchParams;
  const [{ backups, meta, failed }, { applications }, permissions, appPermissions, t] = await Promise.all([
    getBackups(sp),
    getAllApplications(),
    getPermissions(),
    getPermissions("application").catch(() => []),
    getTranslations("backups"),
  ]);

  if (failed) return <LoadFailed description={t("loadFailed")} />;

  const counts = backupCounts(meta);

  // Restore is `backup,manage` — a separate decision from configuring a
  // schedule, because overwriting a live site is not the same trust.
  const canRestore = can(permissions, "backup", "manage");
  // Re-running a failed backup is an app_backup action, not a restore.
  const canRun = can(appPermissions, "app_backup", "manage", "application");
  const hasFilters = Boolean(sp.application || sp.status || sp.type || sp.period);
  // A page past the end is not "nothing has ever run" — the API answers 200
  // with an empty array, and the never-run empty state then contradicts the
  // tallies printed right above it.
  const pageOutOfRange = meta.total > 0 && meta.current_page > meta.last_page;

  return (
    <NavTransitionProvider>
      <div className="space-y-4">
        {pageOutOfRange ? (
          <PageOutOfRange lastPage={meta.last_page} />
        ) : (
        <BackupsHistory
          backups={backups}
          counts={counts}
          applications={applications}
          canRestore={canRestore}
          canRun={canRun}
          hasFilters={hasFilters}
        />
        )}
        {backups.length > 0 ? <DataTablePagination meta={meta} /> : null}
      </div>
    </NavTransitionProvider>
  );
}
