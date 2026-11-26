import { getTranslations } from "next-intl/server";
import { getActivityLog } from "@/lib/activity-log/get-activity-log";
import { getActivityFilters } from "@/lib/activity-log/get-activity-filters";
import { getActivityRetention } from "@/lib/activity-log/get-activity-retention";
import { RetentionButton } from "@/components/admin/activity/retention-button";
import { Caution } from "@/components/ui/caution";
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
  const [{ activity_log: entries, meta, failed, status, failure, message }, filters, retention, t] = await Promise.all([
    getActivityLog(sp),
    getActivityFilters(),
    getActivityRetention(),
    getTranslations("activity"),
  ]);

  const hasFilters = Boolean(sp.search || sp.type || sp.action || sp.kind || sp.security);

  if (failed) {
    return <LoadFailed description={t("loadFailed")} status={status} failure={failure} message={message} />;
  }

  redirectOutOfRange("/admin/activity-log", sp, meta, failed);
  return (
    <div className="space-y-6">
      <PageHeader title={t("title")} actions={retention.retention ? <RetentionButton retention={retention.retention} /> : null} />

      {retention.retention ? null : (
        <Caution size="md">
          <p>{t("retention.loadFailed")}</p>
        </Caution>
      )}

      <NavTransitionProvider>
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
