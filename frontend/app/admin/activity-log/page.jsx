import { getTranslations } from "next-intl/server";
import { getActivityLog } from "@/lib/activity-log/get-activity-log";
import { getActivityFilters } from "@/lib/activity-log/get-activity-filters";
import { ActivityToolbar } from "@/components/activity-log/activity-toolbar";
import { ActivityTable } from "@/components/admin/activity/activity-table";
import { ListCard } from "@/components/data-table/list-card";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { redirectOutOfRange } from "@/lib/tables/redirect-out-of-range";
import { LoadFailed } from "@/components/data-table/load-failed";
import { PageHeader } from "@/components/ui/page-header";

export const dynamic = "force-dynamic";

export default async function AdminActivityLogPage({ searchParams }) {
  const sp = await searchParams;
  const [{ activity_log: entries, meta, failed, status, failure, message }, filters, t] = await Promise.all([
    getActivityLog(sp),
    getActivityFilters(),
    getTranslations("activity"),
  ]);

  const hasFilters = Boolean(sp.search || sp.type || sp.action || sp.kind || sp.security);


  // A typed or bookmarked ?page=99 must not read as an empty log.
  if (failed) {
    // status + failure let the panel name the cause (403 vs 500); the description
    // is the fallback for failures without specific wording.
    return <LoadFailed description={t("loadFailed")} status={status} failure={failure} message={message} />;
  }

  redirectOutOfRange("/admin/activity-log", sp, meta, failed);
  return (
    <div className="space-y-6">
      <PageHeader title={t("title")} subtitle={t("subtitle")} />

      <NavTransitionProvider>
        {/* The pager is not gated on row count: it hides itself when the list is too short. */}
        <ListCard
          toolbar={<ActivityToolbar types={filters.types} actions={filters.actions} kinds={filters.kinds} security />}
          footer={<DataTablePagination meta={meta} />}
        >
          <ActivityTable data={entries} hasFilters={hasFilters} />
        </ListCard>
      </NavTransitionProvider>
    </div>
  );
}
