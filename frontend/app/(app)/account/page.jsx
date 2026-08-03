import { getTranslations } from "next-intl/server";
import { getCurrentUser } from "@/lib/auth/get-current-user";
import { getMyActivity } from "@/lib/account/get-my-activity";
import { getMyActivityFilters } from "@/lib/activity/get-activity-filters";
import { AccountTabs } from "@/components/account/account-tabs";

export const dynamic = "force-dynamic";

export default async function AccountPage({ searchParams }) {
  const sp = await searchParams;
  const [user, { activity_log: entries, meta, failed }, filters, t] = await Promise.all([
    getCurrentUser(),
    getMyActivity(sp, "account"),
    getMyActivityFilters(),
    getTranslations("account"),
  ]);

  return (
    <div className="space-y-6">
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
