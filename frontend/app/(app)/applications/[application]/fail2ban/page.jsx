import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { PageHeader } from "@/components/ui/page-header";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getApplication, getApplicationBans, getApplicationFail2ban } from "@/lib/applications/get-applications";
import { BannedAddressesCard } from "@/components/applications/fail2ban/banned-addresses-card";
import { Fail2banPanel } from "@/components/applications/fail2ban/fail2ban-panel";
import { LoadFailed } from "@/components/data-table/load-failed";
import { PermissionDenied } from "@/components/sections/permission-denied";
import { isSettled } from "@/lib/applications/settled";

export const dynamic = "force-dynamic";

export async function generateMetadata({ params }) {
  const { application } = await params;
  const [t, result] = await Promise.all([
    getTranslations("applications.fail2ban"),
    getApplication(application),
  ]);
  return { title: `${t("pageTitle")} — ${result.application?.name ?? ""}` };
}

export default async function ApplicationFail2banPage({ params }) {
  const { application: id } = await params;
  const [permissions, appPermissions, t, result] = await Promise.all([
    getPermissions(),
    getPermissions("application", id).catch(() => []),
    getTranslations("applications.fail2ban"),
    getApplication(id),
  ]);

  if (!can(permissions, "application", "view")) return <PermissionDenied title={t("pageTitle")} />;
  if (result.status === 404) redirect("/applications?gone=1");
  if (result.failed || !result.application) return <LoadFailed description={t("loadFailed")} status={result.status} failure={result.failure} message={result.message} debug={result.debug} />;

  const application = result.application;
  if (!can(appPermissions, "app_fail2ban", "view", "application")) {
    return <PermissionDenied title={t("pageTitle")} />;
  }

  const canManage = can(appPermissions, "app_fail2ban", "manage", "application");
  const settled = isSettled(application);

  const status = settled ? await getApplicationFail2ban(id) : null;
  const bans = status?.config ? await getApplicationBans(id) : null;

  return (
    <div className="space-y-6">
      <PageHeader title={t("pageTitle")} />

      {!settled ? (
        <div className="rounded-2xl border bg-muted/30 p-6 text-sm text-muted-foreground">
          {t("provisioning")}
        </div>
      ) : status.failed ? (
        <LoadFailed description={t("loadFailed")} status={status.status} failure={status.failure} message={status.message} debug={status.debug} />
      ) : (
        <div className="space-y-4">
          <Fail2banPanel
            appId={id}
            config={status.config}
            jailTemplate={status.jailTemplate}
            filterTemplate={status.filterTemplate}
            canManage={canManage}
            loginRuleOffered={status.loginRuleOffered}
          />
          {bans ? <BannedAddressesCard appId={id} bans={bans} jailName={status.config?.jail_name ?? null} canManage={canManage} /> : null}
        </div>
      )}
    </div>
  );
}
