import { getTranslations } from "next-intl/server";
import { getCurrentUser } from "@/lib/auth/get-current-user";
import { getMyActivity } from "@/lib/account/get-my-activity";
import { getMyActivityFilters } from "@/lib/activity-log/get-activity-filters";
import { AccountTabs } from "@/components/account/account-tabs";
import { PageCrumb } from "@/components/sections/page-crumb";
import { redirectOutOfRange } from "@/lib/tables/redirect-out-of-range";
import { PageHeader } from "@/components/ui/page-header";
import { AccountIdentity } from "@/components/account/account-identity";

export const dynamic = "force-dynamic";

export default async function AccountPage({ searchParams }) {
  const sp = await searchParams;
  const [user, { activity_log: entries, meta, failed, status, failure, message }, filters, t, signIns, passwordChanges] = await Promise.all([
    getCurrentUser(),
    getMyActivity(sp, "account"),
    getMyActivityFilters(),
    getTranslations("account"),
    getMyActivity({ action: "logged_in" }, "account"),
    getMyActivity({ action: "password_changed" }, "account"),
  ]);

  redirectOutOfRange("/account", sp, meta, failed);
  return (
    <div className="space-y-6">
      <PageCrumb root>{t("title")}</PageCrumb>
      <PageHeader title={t("title")} />
      <AccountIdentity user={user} />

      <AccountTabs
        user={user}
        entries={entries}
        meta={meta}
        activityFailed={failed}
        activityStatus={status}
        activityFailure={failure}
        activityMessage={message}
        filters={filters}
        signIns={signIns.activity_log.slice(0, 5)}
        signInsFailed={signIns.failed}
        passwordChanged={passwordChanges.failed ? null : (passwordChanges.activity_log[0]?.created_at_human ?? null)}
        isFiltered={Boolean(sp.search || sp.type || sp.action)}
      />
    </div>
  );
}
