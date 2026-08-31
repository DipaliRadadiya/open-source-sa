import { getTranslations } from "next-intl/server";
import { getCurrentUser } from "@/lib/auth/get-current-user";
import { getMyActivity } from "@/lib/account/get-my-activity";
import { getMyActivityFilters } from "@/lib/activity/get-activity-filters";
import { AccountTabs } from "@/components/account/account-tabs";
import { PageCrumb } from "@/components/sections/page-crumb";
import { redirectOutOfRange } from "@/lib/tables/redirect-out-of-range";

export const dynamic = "force-dynamic";

export default async function AccountPage({ searchParams }) {
  const sp = await searchParams;
  const [user, { activity_log: entries, meta, failed }, filters, t] = await Promise.all([
    getCurrentUser(),
    getMyActivity(sp, "account"),
    getMyActivityFilters(),
    getTranslations("account"),
  ]);


  // Read-only, so a delete cannot strand anyone here — but a typed or
  // bookmarked ?page=99 still would, and it must not read as an empty log.
  redirectOutOfRange("/account", sp, meta, failed);
  return (
    <div className="space-y-6">
      {/* Reached from the user menu, not the sidebar, so it names its own trail. */}
      <PageCrumb root>{t("title")}</PageCrumb>
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
      </div>

      <AccountTabs
        user={user}
        entries={entries}
        meta={meta}
        // Only the history failed — the profile half of this page is fine.
        activityFailed={failed}
        filters={filters}
        isFiltered={Boolean(sp.search || sp.type || sp.action)}
      />
    </div>
  );
}
