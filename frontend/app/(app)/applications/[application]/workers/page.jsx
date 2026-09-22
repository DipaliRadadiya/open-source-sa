import { PageHeader } from "@/components/ui/page-header";
import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getApplication } from "@/lib/applications/get-applications";
import { getWorkers } from "@/lib/applications/get-workers";
import { getServices } from "@/lib/services/get-services";
import { WorkersPanel } from "@/components/applications/workers/workers-panel";
import { LoadFailed } from "@/components/data-table/load-failed";
import { PermissionDenied } from "@/components/sections/permission-denied";

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
  // The site is gone. Land on the list — the only place left to go — and say
  // why on arrival, rather than parking on a dead end that offers one link.
  if (result.status === 404) redirect("/applications?gone=1");
  if (result.failed || !result.application)
    return <LoadFailed description={t("loadFailed")} status={result.status} failure={result.failure} message={result.message} debug={result.debug} />;

  const application = result.application;
  // Granted only for site types that keep something to supervise (git, Node,
  // Craft, Statamic, blank PHP) — a missing grant here means the screen
  // shouldn't exist for this site, the same contract as Environment/Deployment.
  if (!can(appPermissions, "app_worker", "view", "application")) {
    redirect(`/applications/${id}`);
  }
  const canManage = can(appPermissions, "app_worker", "manage", "application");
  const settled = application.status === "active";

  /*
   * Whether supervisord is on the box, read from the services list.
   *
   * A service nobody has ever installed is absent from that list entirely —
   * ServiceManager returns null for it — so "no supervisor entry" is a reliable
   * "not installed", and it needs no new endpoint.
   *
   * Without this the page looked completely normal on a server with no
   * supervisord, and the only way to find out was to fill in the whole worker
   * form and submit it: `POST /workers` answers 202 and starts an apt install
   * instead of creating anything.
   *
   * A failure here is not an error on this page — it just means we cannot say,
   * and the create dialog still handles the 202 the way it always did.
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
        />
      )}
    </div>
  );
}
