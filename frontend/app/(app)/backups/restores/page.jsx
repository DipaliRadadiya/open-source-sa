import { getTranslations } from "next-intl/server";
import { getRestores } from "@/lib/backups/get-backups";
import { getAllApplications } from "@/lib/applications/get-applications";
import { RestoresList } from "@/components/backups/restores-list";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { LoadFailed } from "@/components/data-table/load-failed";
import { redirectOutOfRange } from "@/lib/tables/redirect-out-of-range";

export const dynamic = "force-dynamic";

/**
 * Every restore this server has run.
 *
 * No permission check of its own: the layout already gates the whole section
 * on `backup,view`, and anyone who may see which backups exist may see which
 * of them were put back over a live site — arguably more so.
 */
export default async function RestoresPage({ searchParams }) {
  const sp = await searchParams;
  const [{ restores, meta, failed }, { applications }, t] = await Promise.all([
    getRestores(sp),
    getAllApplications(),
    getTranslations("backups"),
  ]);

  if (failed) return <LoadFailed description={t("loadFailed")} />;

  // Which empty state to show: "nothing has ever been restored" and "nothing
  // matches these filters" are different facts, and only one of them is
  // solved by changing a dropdown.
  const hasFilters = Boolean(sp.application || sp.status || sp.type || sp.period);


  // Before anything renders: a page past the end sends the reader to the
  // last real page instead of painting an error for it.
  redirectOutOfRange("/backups/restores", sp, meta, failed);
  return (
    <NavTransitionProvider>
      <div className="space-y-4">
        <RestoresList restores={restores} applications={applications} hasFilters={hasFilters} />
        {/* Not behind a row count. The selector hides itself when the list is
            too short to paginate, and gating it on the current page as well is
            how it used to vanish on the very page you needed it — see the note
            in data-table-pagination.jsx. */}
        <DataTablePagination meta={meta} />
      </div>
    </NavTransitionProvider>
  );
}
