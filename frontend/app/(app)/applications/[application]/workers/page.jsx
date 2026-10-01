import { PageHeader } from "@/components/ui/page-header";
import { notFound, redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getApplication } from "@/lib/applications/get-applications";
import { getWorkers } from "@/lib/applications/get-workers";
import { getServices } from "@/lib/services/get-services";
import { WorkersPanel } from "@/components/applications/workers/workers-panel";
import { LoadFailed } from "@/components/data-table/load-failed";
import { PermissionDenied } from "@/components/sections/permission-denied";
import { isSettled } from "@/lib/applications/settled";

export const dynamic = "force-dynamic";

export async function generateMetadata({ params }) {
  const { application } = await params;
  const [t, result] = await Promise.all([
    getTranslations("applications.workers"),
    getApplication(application),
  ]);
  return { title: `${t("pageTitle")} — ${result.application?.name ?? ""}` };
}

export default async function ApplicationWorkersPage({ params }) {
  const { application: id } = await params;
  const [permissions, appPermissions, t, result] = await Promise.all([
    getPermissions(),
    getPermissions("application", id).catch(() => []),
    getTranslations("applications.workers"),
    getApplication(id),
  ]);

  if (!can(permissions, "application", "view")) return <PermissionDenied title={t("pageTitle")} />;
  // The site is gone: back to the list, which explains why.
  if (result.status === 404) redirect("/applications?gone=1");
  if (result.failed || !result.application)
    return <LoadFailed description={t("loadFailed")} status={result.status} failure={result.failure} message={result.message} debug={result.debug} />;

  const application = result.application;
  // Granted only for site types with something to supervise; same contract
  // as Environment/Deployment.
  if (!can(appPermissions, "app_worker", "view", "application")) {
    // 404: site type has nothing to supervise; 403: missing grant.
    if ((await getWorkers(id)).status === 404) notFound();
    return <PermissionDenied title={t("pageTitle")} />;
  }
  const canManage = can(appPermissions, "app_worker", "manage", "application");
  const settled = isSettled(application);

  /*
   * Whether supervisord is installed: an uninstalled service is absent from the
   * services list. Without it, `POST /workers` answers 202 and starts an apt
   * install instead. A failed read just means unknown.
   */
  const [workersResult, services] = settled
    ? await Promise.all([getWorkers(id), getServices().catch(() => ({ services: [], failed: true }))])
    : [{ workers: [], presets: [], checks: [], failed: false }, { services: [], failed: true }];

  const supervisorMissing =
    !services.failed && !services.services.some((service) => service.key === "supervisor");

  return (
    <div className="space-y-6">
      <PageHeader
        title={t("pageTitle")}
        subtitle={t("pageSubtitle")}
      />

      {!settled ? (
        <div className="rounded-2xl border bg-muted/30 p-6 text-sm text-muted-foreground">
          {t("provisioning")}
        </div>
      ) : workersResult.failed ? (
        <LoadFailed description={t("loadFailed")} status={workersResult.status} failure={workersResult.failure} message={workersResult.message} debug={workersResult.debug} />
      ) : (
        <WorkersPanel
          appId={id}
          initialWorkers={workersResult.workers}
          initialPresets={workersResult.presets}
          initialChecks={workersResult.checks}
          supervisorMissing={supervisorMissing}
          canManage={canManage}
          siteUser={application.system_user?.username ?? null}
          appRoot={(application.path ?? "").replace(/\/public_html\/?$/, "")}
          canViewLogs={can(permissions, "logs", "view")}
        />
      )}
    </div>
  );
}
