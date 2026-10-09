import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getServerActivity } from "@/lib/activity-log/get-server-activity";
import { getActivityFilters, getMyActivityFilters } from "@/lib/activity-log/get-activity-filters";
import { getCurrentUser } from "@/lib/auth/get-current-user";
import { ActivityToolbar } from "@/components/activity-log/activity-toolbar";
import { typesForScope, actionsForScope } from "@/lib/activity-log/labels";
import { MyActivityTable } from "@/components/activity-log/my-activity-table";
import { ListCard } from "@/components/data-table/list-card";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { LoadFailed } from "@/components/data-table/load-failed";
import { redirectOutOfRange } from "@/lib/tables/redirect-out-of-range";
import { PageHeader } from "@/components/ui/page-header";
import { PermissionDenied } from "@/components/sections/permission-denied";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("activity");
  return { title: t("server.title") };
}

export default async function ActivityLogPage({ searchParams }) {
  const sp = await searchParams;
  const [permissions, t] = await Promise.all([
    getPermissions(),
    getTranslations("activity"),
  ]);

  if (!can(permissions, "activity_log", "view")) return <PermissionDenied title={t("title")} />;
  const user = await getCurrentUser();
  const [{ activity_log: entries, meta, failed, status, failure, message }, filters] = await Promise.all([
    getServerActivity(sp),
    // No server-wide filter list exists yet: admins get the full catalog,
    // everyone else the types they have touched.
    user?.is_admin ? getActivityFilters() : getMyActivityFilters(),
  ]);

  const isFiltered = Boolean(sp.search || sp.type || sp.action);


  // A bookmarked out-of-range ?page must not read as an empty log.
  redirectOutOfRange("/activity-log", sp, meta, failed);
  return (
    <div className="space-y-6">
      <PageHeader title={t("server.title")} subtitle={t("server.subtitle")} />

      {failed ? (
        <LoadFailed description={t("server.loadFailed")} status={status} failure={failure} message={message} />
      ) : (
        <NavTransitionProvider>
          {/* The pager is not gated on row count: it hides itself when there is nothing to page. */}
          <ListCard
            toolbar={
              <ActivityToolbar
                // The filters endpoint spans both scopes; this page is server-only.
                types={typesForScope(filters.types, "server")}
                actions={actionsForScope(filters.actions, filters.types, "server")}
                searchKey="server.searchPlaceholder"
              />
            }
            footer={<DataTablePagination meta={meta} />}
          >
            <MyActivityTable
              data={entries}
              showUser
              emptyMessage={isFiltered ? t("mine.emptyFiltered") : t("server.empty")}
              hasFilters={isFiltered}
            />
          </ListCard>
        </NavTransitionProvider>
      )}
    </div>
  );
}
