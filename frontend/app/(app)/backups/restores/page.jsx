import { getTranslations } from "next-intl/server";
import { getRestores } from "@/lib/backups/get-backups";
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

// No permission check here: the layout gates the section on `backup,view`.
export default async function RestoresPage({ searchParams }) {
  const sp = await searchParams;
  const [{ restores, meta, failed, status, failure, message }, { applications, failed: applicationsFailed }, permissions, t] = await Promise.all([
    getRestores(sp),
    getAllApplications(),
    getPermissions(),
    getTranslations("backups"),
  ]);

  // Only a refusal (422) can be the filter; anything else is a real failure.
  if (failed && status === 422) redirectUnknownApplication("/backups/restores", sp, applications, applicationsFailed);
  if (failed) return <LoadFailed description={t("loadFailed")} status={status} failure={failure} message={message} />;

  // "Nothing restored yet" and "nothing matches these filters" need different empty states.
  const hasFilters = Boolean(sp.application || sp.status || sp.type || sp.period);


  // A page past the end redirects to the last real page.
  redirectOutOfRange("/backups/restores", sp, meta, failed);
  return (
    <NavTransitionProvider>
      <div className="space-y-4">
        <RestoresList
          restores={restores}
          applications={applications}
          hasFilters={hasFilters}
          // Undo is a restore, which overwrites the live site: `backup` manage, as in History.
          canRestore={can(permissions, "backup", "manage")}
        />
        {/* Not gated on row count: the selector hides itself when too short to paginate. */}
        <DataTablePagination meta={meta} />
      </div>
    </NavTransitionProvider>
  );
}
