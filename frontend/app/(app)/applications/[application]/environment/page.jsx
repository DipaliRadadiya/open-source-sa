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
  // The site is gone: back to the list, which explains why.
  if (result.status === 404) redirect("/applications?gone=1");
  if (result.failed || !result.application)
    return <LoadFailed description={t("loadFailed")} status={result.status} failure={result.failure} message={result.message} debug={result.debug} />;

  const application = result.application;
  // 403: not allowed; 404: the site type has no .env (e.g. WordPress).
  // They need different messages.
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

  // Fetched in parallel with the environment read to avoid adding latency.
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
          <EnvironmentHistoryCard
            appId={id}
            entries={historyResult.history}
            meta={historyResult.meta}
            failed={historyResult.failed}
            canManage={canManage}
            // Same signal as the editor's restore dialog, from the same payload.
            requiresRestart={envResult.environment.requires_restart}
          />
        </>
      )}
    </div>
  );
}
