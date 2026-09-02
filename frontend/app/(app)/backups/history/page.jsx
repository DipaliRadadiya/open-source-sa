import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { backupCounts, getBackups } from "@/lib/backups/get-backups";
import { getAllApplications } from "@/lib/applications/get-applications";
import { BackupsHistory } from "@/components/backups/backups-history";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { LoadFailed } from "@/components/data-table/load-failed";
import { redirectOutOfRange } from "@/lib/tables/redirect-out-of-range";

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
  // Before anything renders: a page past the end sends the reader to the
  // last real page instead of painting an error for it.
  redirectOutOfRange("/backups/history", sp, meta, failed);
  return (
    <NavTransitionProvider>
      <div className="space-y-4">
        <BackupsHistory
          backups={backups}
          counts={counts}
          applications={applications}
          canRestore={canRestore}
          canRun={canRun}
          hasFilters={hasFilters}
        />
        {/* Not behind a row count. The selector hides itself when the list is
            too short to paginate, and gating it on the current page as well is
            how it used to vanish on the very page you needed it — see the note
            in data-table-pagination.jsx. */}
        <DataTablePagination meta={meta} />
      </div>
    </NavTransitionProvider>
  );
}
