import { getTranslations } from "next-intl/server";
import { getRestores } from "@/lib/backups/get-backups";
import { getAllApplications } from "@/lib/applications/get-applications";
import { RestoresList } from "@/components/backups/restores-list";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { LoadFailed } from "@/components/data-table/load-failed";
import { redirectOutOfRange } from "@/lib/tables/redirect-out-of-range";

export const dynamic = "force-dynamic";

// No permission check here: the layout gates the section on `backup,view`.
export default async function RestoresPage({ searchParams }) {
  const sp = await searchParams;
  const [{ restores, meta, failed, status, failure, message }, { applications }, t] = await Promise.all([
    getRestores(sp),
    getAllApplications(),
    getTranslations("backups"),
  ]);

  if (failed) return <LoadFailed description={t("loadFailed")} status={status} failure={failure} message={message} />;

  // "Nothing restored yet" and "nothing matches these filters" need different empty states.
  const hasFilters = Boolean(sp.application || sp.status || sp.type || sp.period);


  // A page past the end redirects to the last real page.
  redirectOutOfRange("/backups/restores", sp, meta, failed);
  return (
    <NavTransitionProvider>
      <div className="space-y-4">
        <RestoresList restores={restores} applications={applications} hasFilters={hasFilters} />
        {/* Not gated on row count: the selector hides itself when too short to paginate. */}
        <DataTablePagination meta={meta} />
      </div>
    </NavTransitionProvider>
  );
}
