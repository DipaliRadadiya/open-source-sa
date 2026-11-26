import { getTranslations } from "next-intl/server";
import { getDatabaseBackupCoverage, getRestores } from "@/lib/backups/get-backups";
import { getDatabase } from "@/lib/databases/get-database";
import { redirect } from "next/navigation";
import { filterDatabases } from "@/lib/backups/filter-databases";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getAllApplications } from "@/lib/applications/get-applications";
import { RestoresList } from "@/components/backups/restores-list";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { LoadFailed } from "@/components/data-table/load-failed";
import { redirectOutOfRange } from "@/lib/tables/redirect-out-of-range";
import { redirectUnknownApplication } from "@/lib/tables/redirect-unknown-application";

export const dynamic = "force-dynamic";

export default async function RestoresPage({ searchParams }) {
  const sp = await searchParams;
  const [{ restores, meta, failed, status, failure, message }, { applications, failed: applicationsFailed }, permissions, t, coverage, database] = await Promise.all([
    getRestores(sp),
    getAllApplications(),
    getPermissions(),
    getTranslations("backups"),
    getDatabaseBackupCoverage(),
    sp.database ? getDatabase(sp.database) : null,
  ]);

  if (failed && status === 422 && database?.status === 404) {
    const rest = new URLSearchParams(Object.entries(sp).filter(([key, v]) => key !== "database" && key !== "page" && typeof v === "string")).toString();
    redirect(rest ? `/backups/restores?${rest}` : "/backups/restores");
  }
  if (failed && status === 422) redirectUnknownApplication("/backups/restores", sp, applications, applicationsFailed);
  if (failed) return <LoadFailed description={t("loadFailed")} status={status} failure={failure} message={message} />;

  const hasFilters = Boolean(sp.application || sp.database || sp.status || sp.type || sp.period);

  redirectOutOfRange("/backups/restores", sp, meta, failed);
  return (
    <NavTransitionProvider>
      <div className="space-y-4">
        <RestoresList
          restores={restores}
          applications={applications}
          databases={filterDatabases(coverage.rows, sp.database ? { id: sp.database, name: database?.data?.name } : null)}
          hasFilters={hasFilters}
          canRestore={can(permissions, "backup", "manage")}
          pager={<DataTablePagination meta={meta} />}
        />
      </div>
    </NavTransitionProvider>
  );
}

