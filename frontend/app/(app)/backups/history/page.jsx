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
import { redirectUnknownApplication } from "@/lib/tables/redirect-unknown-application";

export const dynamic = "force-dynamic";

export default async function BackupsHistoryPage({ searchParams }) {
  const sp = await searchParams;
  const [{ backups, meta, failed, status, failure, message }, { applications, failed: applicationsFailed }, permissions, appPermissions, t] = await Promise.all([
    getBackups(sp),
    getAllApplications(),
    getPermissions(),
    getPermissions("application").catch(() => []),
    getTranslations("backups"),
  ]);

  // Only a refusal (422) can be the filter; anything else is a real failure.
  if (failed && status === 422) redirectUnknownApplication("/backups/history", sp, applications, applicationsFailed);
  if (failed) return <LoadFailed description={t("loadFailed")} status={status} failure={failure} message={message} />;

  const counts = backupCounts(meta);

  // Restore overwrites a live site, so it needs `backup,manage`.
  const canRestore = can(permissions, "backup", "manage");
  // Re-running a failed backup is an app_backup action, not a restore.
  const canRun = can(appPermissions, "app_backup", "manage", "application");
  const hasFilters = Boolean(sp.application || sp.status || sp.type || sp.period);
  // A page past the end redirects to the last real page instead of erroring.
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
          pager={<DataTablePagination meta={meta} />}
        />
      </div>
    </NavTransitionProvider>
  );
}
