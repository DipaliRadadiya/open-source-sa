import { getTranslations } from "next-intl/server";
import { getActivityLog } from "@/lib/activity/get-activity-log";
import { getActivityFilters } from "@/lib/activity/get-activity-filters";
import { ActivityToolbar } from "@/components/activity/activity-toolbar";
import { ActivityTable } from "@/components/admin/activity/activity-table";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { redirectOutOfRange } from "@/lib/tables/redirect-out-of-range";

export const dynamic = "force-dynamic";

export default async function AdminActivityLogPage({ searchParams }) {
  const sp = await searchParams;
  const [{ activity_log: entries, meta }, filters, t] = await Promise.all([
    getActivityLog(sp),
    getActivityFilters(),
    getTranslations("activity"),
  ]);

  const hasFilters = Boolean(sp.search || sp.type || sp.action);


  // Read-only, so a delete cannot strand anyone here — but a typed or
  // bookmarked ?page=99 still would, and it must not read as an empty log.
  redirectOutOfRange("/admin/activity-log", sp, meta);
  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
      </div>

      <NavTransitionProvider>
        <ActivityToolbar types={filters.types} actions={filters.actions} />
        <ActivityTable data={entries} hasFilters={hasFilters} />
        {entries.length > 0 ? <DataTablePagination meta={meta} /> : null}
      </NavTransitionProvider>
    </div>
  );
}
