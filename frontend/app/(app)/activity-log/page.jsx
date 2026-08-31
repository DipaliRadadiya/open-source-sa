import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getMyActivity } from "@/lib/account/get-my-activity";
import { getMyActivityFilters } from "@/lib/activity/get-activity-filters";
import { ActivityToolbar } from "@/components/activity/activity-toolbar";
import { typesForScope, actionsForScope } from "@/lib/activity/labels";
import { MyActivityTable } from "@/components/activity/my-activity-table";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { LoadFailed } from "@/components/data-table/load-failed";
import { redirectOutOfRange } from "@/lib/tables/redirect-out-of-range";

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

  if (!can(permissions, "activity_log", "view")) redirect("/dashboard");

  const [{ activity_log: entries, meta, failed }, filters] = await Promise.all([
    getMyActivity(sp, "server"),
    getMyActivityFilters(),
  ]);

  const isFiltered = Boolean(sp.search || sp.type || sp.action);


  // Read-only, so a delete cannot strand anyone here — but a typed or
  // bookmarked ?page=99 still would, and it must not read as an empty log.
  redirectOutOfRange("/activity-log", sp, meta, failed);
  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("mine.title")}</h1>
        {/* Said out loud, because the missing "who" column is the only other
            clue that this is your history and not the server's. */}
        <p className="text-sm text-muted-foreground">{t("mine.subtitle")}</p>
      </div>

      {failed ? (
        <LoadFailed description={t("mine.loadFailed")} />
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
          />
          {entries.length > 0 ? <DataTablePagination meta={meta} /> : null}
        </NavTransitionProvider>
      )}
    </div>
  );
}
