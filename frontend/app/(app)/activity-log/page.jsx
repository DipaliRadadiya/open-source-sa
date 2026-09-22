import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getMyActivity } from "@/lib/account/get-my-activity";
import { getMyActivityFilters } from "@/lib/activity-log/get-activity-filters";
import { ActivityToolbar } from "@/components/activity-log/activity-toolbar";
import { typesForScope, actionsForScope } from "@/lib/activity-log/labels";
import { MyActivityTable } from "@/components/activity-log/my-activity-table";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { LoadFailed } from "@/components/data-table/load-failed";
import { redirectOutOfRange } from "@/lib/tables/redirect-out-of-range";
import { PageHeader } from "@/components/ui/page-header";
import { PermissionDenied } from "@/components/sections/permission-denied";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("activity");
  return { title: t("mine.title") };
}

export default async function ActivityLogPage({ searchParams }) {
  const sp = await searchParams;
  const [permissions, t] = await Promise.all([
    getPermissions(),
    getTranslations("activity"),
  ]);

  if (!can(permissions, "activity_log", "view")) return <PermissionDenied title={t("title")} />;
  const [{ activity_log: entries, meta, failed, status, failure, message }, filters] = await Promise.all([
    getMyActivity(sp, "server"),
    getMyActivityFilters(),
  ]);

  const isFiltered = Boolean(sp.search || sp.type || sp.action);


  // Read-only, so a delete cannot strand anyone here — but a typed or
  // bookmarked ?page=99 still would, and it must not read as an empty log.
  redirectOutOfRange("/activity-log", sp, meta, failed);
  return (
    <div className="space-y-6">
      {/* Said out loud, because the missing "who" column is the only other
      clue that this is your history and not the server's. */}
      <PageHeader title={t("mine.title")} subtitle={t("mine.subtitle")} />

      {failed ? (
        <LoadFailed description={t("mine.loadFailed")} status={status} failure={failure} message={message} />
      ) : (
        <NavTransitionProvider>
          <ActivityToolbar
            // The filters endpoint spans both scopes; this page is server-only.
            types={typesForScope(filters.types, "server")}
            actions={actionsForScope(filters.actions, filters.types, "server")}
            searchKey="mine.searchPlaceholder"
          />
          <MyActivityTable
            data={entries}
            emptyMessage={isFiltered ? t("mine.emptyFiltered") : t("mine.empty")}
            hasFilters={isFiltered}
          />
          {/* Not behind a row count: the selector hides itself when the list is too
              short to paginate, and gating it on the current page as well is how it
              used to vanish on the very page you needed it. */}
          <DataTablePagination meta={meta} />
        </NavTransitionProvider>
      )}
    </div>
  );
}
