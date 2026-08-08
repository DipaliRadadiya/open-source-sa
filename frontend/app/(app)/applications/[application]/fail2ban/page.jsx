import { headers } from "next/headers";
import { notFound, redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { PageHeader } from "@/components/ui/page-header";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getApplication, getApplicationFail2ban } from "@/lib/applications/get-applications";
import { Fail2banPanel } from "@/components/applications/fail2ban/fail2ban-panel";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

export async function generateMetadata({ params }) {
  const { application } = await params;
  const [t, result] = await Promise.all([
    getTranslations("applications.fail2ban"),
    getApplication(application),
  ]);
  return { title: `${t("pageTitle")} — ${result.application?.name ?? ""}` };
}

/**
 * The address this request came from.
 *
 * Used for one thing: telling someone that the address they are reading this
 * page from is the one that has been banned. Read here rather than in the
 * browser because the browser cannot know its own public address without
 * asking a third party.
 *
 * `x-forwarded-for` is a list when proxies chain; the first entry is the
 * client. It is spoofable in principle, but the only thing it does here is
 * highlight a row and offer a button that is already on every other row.
 */
async function viewerAddress() {
  const list = (await headers()).get("x-forwarded-for");
  return list ? (list.split(",")[0]?.trim() ?? null) : null;
}

export default async function ApplicationFail2banPage({ params }) {
  const { application: id } = await params;
  const [permissions, appPermissions, t, result, viewerIp] = await Promise.all([
    getPermissions(),
    getPermissions("application", id).catch(() => []),
    getTranslations("applications.fail2ban"),
    getApplication(id),
    viewerAddress(),
  ]);

  if (!can(permissions, "application", "view")) redirect("/dashboard");
  if (result.status === 404) notFound();
  if (result.failed || !result.application) return <LoadFailed description={t("loadFailed")} />;

  const application = result.application;
  if (!can(appPermissions, "app_fail2ban", "view", "application")) {
    redirect(`/applications/${id}`);
  }

  const canManage = can(appPermissions, "app_fail2ban", "manage", "application");
  const settled = application.status === "active";

  const status = settled ? await getApplicationFail2ban(id) : null;

  return (
    <div className="space-y-6">
      <PageHeader
        backHref={`/applications/${id}`}
        backLabel={t("back")}
        title={t("pageTitle")}
        subtitle={t("pageSubtitle")}
      />

      {!settled ? (
        <div className="rounded-2xl border bg-muted/30 p-6 text-sm text-muted-foreground">
          {t("provisioning")}
        </div>
      ) : status.failed ? (
        // "We could not ask" must never render as "nothing is protecting you".
        <LoadFailed description={t("loadFailed")} />
      ) : (
        <Fail2banPanel
          appId={id}
          enabled={status.enabled}
          jails={status.jails}
          viewerIp={viewerIp}
          canManage={canManage}
        />
      )}
    </div>
  );
}
