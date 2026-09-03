import { getTranslations } from "next-intl/server";
import { getActivityLog } from "@/lib/activity-log/get-activity-log";
import { getActivityFilters } from "@/lib/activity-log/get-activity-filters";
import { ActivityToolbar } from "@/components/activity-log/activity-toolbar";
import { ActivityTable } from "@/components/admin/activity/activity-table";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { redirectOutOfRange } from "@/lib/tables/redirect-out-of-range";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

export default async function AdminActivityLogPage({ searchParams }) {
  const sp = await searchParams;
  const [{ activity_log: entries, meta, failed, status, failure }, filters, t] = await Promise.all([
    getActivityLog(sp),
    getActivityFilters(),
    getTranslations("activity"),
  ]);

  const hasFilters = Boolean(sp.search || sp.type || sp.action);


  // Read-only, so a delete cannot strand anyone here — but a typed or
  // bookmarked ?page=99 still would, and it must not read as an empty log.
  if (failed) {
    // status + failure let the panel name the cause — a 403 is the reader's
    // situation, a 500 is ours. The description is the fallback for the
    // failures it has no specific words for.
    return <LoadFailed description={t("loadFailed")} status={status} failure={failure} />;
  }

  redirectOutOfRange("/admin/activity-log", sp, meta, failed);
  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
      </div>

      <NavTransitionProvider>
        <ActivityToolbar types={filters.types} actions={filters.actions} />
        <ActivityTable data={entries} hasFilters={hasFilters} />
        {/* Not behind a row count: the selector hides itself when the list is too
            short to paginate, and gating it on the current page as well is how it
            used to vanish on the very page you needed it. */}
        <DataTablePagination meta={meta} />
      </NavTransitionProvider>
    </div>
  );
}
