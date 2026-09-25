import { PageHeader } from "@/components/ui/page-header";
import { notFound, redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getApplication } from "@/lib/applications/get-applications";
import { getApplicationEnvironment } from "@/lib/applications/get-application-environment";
import { getEnvironmentHistory } from "@/lib/applications/get-environment-history";
import { EnvironmentEditor } from "@/components/applications/environment/environment-editor";
import { EnvironmentHistoryCard } from "@/components/applications/environment/environment-history-card";
import { LoadFailed } from "@/components/data-table/load-failed";
import { PermissionDenied } from "@/components/sections/permission-denied";
import { isSettled } from "@/lib/applications/settled";

export const dynamic = "force-dynamic";

export async function generateMetadata({ params }) {
  const { application } = await params;
  const [t, result] = await Promise.all([
    getTranslations("applications.environment"),
    getApplication(application),
  ]);
  return { title: `${t("pageTitle")} — ${result.application?.name ?? ""}` };
}

export default async function ApplicationEnvironmentPage({ params }) {
  const { application: id } = await params;
  const [permissions, appPermissions, t, result] = await Promise.all([
    getPermissions(),
    getPermissions("application", id).catch(() => []),
    getTranslations("applications.environment"),
    getApplication(id),
  ]);

  if (!can(permissions, "application", "view")) return <PermissionDenied title={t("pageTitle")} />;
  // The site is gone. Land on the list — the only place left to go — and say
  // why on arrival, rather than parking on a dead end that offers one link.
  if (result.status === 404) redirect("/applications?gone=1");
  if (result.failed || !result.application)
    return <LoadFailed description={t("loadFailed")} status={result.status} failure={result.failure} message={result.message} debug={result.debug} />;

  const application = result.application;
  // The grant is missing both for someone not allowed to see the file and for
  // a site type that keeps no .env at all (WordPress's config is wp-config.php).
  // The API tells them apart — 403 against 404 — and they are different
  // sentences: telling an administrator "you don't have access" sent them off
  // to find a permission that does not exist.
  if (!can(appPermissions, "app_environment", "view", "application")) {
    if ((await getApplicationEnvironment(id)).status === 404) notFound();
    return <PermissionDenied title={t("pageTitle")} />;
  }
  const canManage = can(
    appPermissions,
    "app_environment",
    "manage",
    "application",
  );
  const settled = isSettled(application);

  // Together: the history is a log query and one directory listing, and running
  // it after the environment read would add its latency to a page that already
  // waits on several shell-outs.
  const [envResult, historyResult] = settled
    ? await Promise.all([getApplicationEnvironment(id), getEnvironmentHistory(id)])
    : [
        { environment: null, failed: false },
        { history: null, failed: false },
      ];

  return (
    <div className="space-y-6">
      <PageHeader
        title={t("pageTitle")}
        subtitle={canManage ? t("pageSubtitle") : t("pageSubtitleReadOnly")}
      />

      {!settled ? (
        <div className="rounded-2xl border bg-muted/30 p-6 text-sm text-muted-foreground">
          {t("provisioning")}
        </div>
      ) : envResult.failed || !envResult.environment ? (
        <LoadFailed description={t("loadFailed")} status={envResult.status} failure={envResult.failure} message={envResult.message} debug={envResult.debug} />
      ) : (
        <>
          <EnvironmentEditor
            appId={id}
            initialEnv={envResult.environment}
            canManage={canManage}
          />
          {/* Below the editor: the file is what people came for, its history
              is what they check afterwards. */}
          <EnvironmentHistoryCard
            appId={id}
            entries={historyResult.history}
            meta={historyResult.meta}
            failed={historyResult.failed}
            canManage={canManage}
            // Same signal the editor's restore dialog uses, from the same
            // payload — so both doors to this action offer the same choice.
            requiresRestart={envResult.environment.requires_restart}
          />
        </>
      )}
    </div>
  );
}
