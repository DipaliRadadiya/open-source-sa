import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getBackups } from "@/lib/backups/get-backups";
import { ArchivedBackups } from "@/components/backups/archived-backups";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { LoadFailed } from "@/components/data-table/load-failed";
import { redirectOutOfRange } from "@/lib/tables/redirect-out-of-range";

export const dynamic = "force-dynamic";

// Backups kept after their application or database was deleted (16 Oct).
export default async function ArchivedBackupsPage({ searchParams }) {
  const sp = await searchParams;
  const [{ backups, meta, failed, status, failure, message }, permissions, t] = await Promise.all([
    getBackups({ page: sp.page, per_page: sp.per_page, type: sp.type, period: sp.period, archived: true }),
    getPermissions(),
    getTranslations("backups.archivedTab"),
  ]);

  if (failed) return <LoadFailed description={t("loadFailed")} status={status} failure={failure} message={message} />;
  redirectOutOfRange("/backups/archived", sp, meta, failed);

  return (
    <NavTransitionProvider>
      <ArchivedBackups
        backups={backups}
        // Downloading and deleting both hand over or remove a whole archive.
        canManage={can(permissions, "backup", "manage")}
        hasFilters={Boolean(sp.type || sp.period)}
        pager={<DataTablePagination meta={meta} />}
      />
    </NavTransitionProvider>
  );
}
