import { notFound, redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { PageHeader } from "@/components/ui/page-header";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getApplication, getApplicationStaging } from "@/lib/applications/get-applications";
import { StagingPanel } from "@/components/applications/staging/staging-panel";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

export async function generateMetadata({ params }) {
  const { application } = await params;
  const [t, result] = await Promise.all([
    getTranslations("applications.staging"),
    getApplication(application),
  ]);
  return { title: `${t("pageTitle")} — ${result.application?.name ?? ""}` };
}

export default async function ApplicationStagingPage({ params }) {
  const { application: id } = await params;
  const [permissions, appPermissions, t, result] = await Promise.all([
    getPermissions(),
    getPermissions("application", id).catch(() => []),
    getTranslations("applications.staging"),
    getApplication(id),
  ]);

  if (!can(permissions, "application", "view")) redirect("/dashboard");
  if (result.status === 404) notFound();
  if (result.failed || !result.application) return <LoadFailed description={t("loadFailed")} status={result.status} failure={result.failure} />;

  const application = result.application;
  if (!can(appPermissions, "app_staging", "view", "application")) {
    redirect(`/applications/${id}`);
  }

  const canManage = can(appPermissions, "app_staging", "manage", "application");
  const settled = application.status === "active";

  const staging = settled ? await getApplicationStaging(id) : null;

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
      ) : staging.status === 404 ? (
        // Staging is WordPress-only. For every other site type the endpoint
        // answers 404, which is a fact about the site rather than a failure to
        // read it — so it reads as an answer, not an error.
        <div className="rounded-2xl border bg-muted/30 p-6 text-sm text-muted-foreground">
          {t("unsupported", { type: application.site_type_title ?? application.site_type })}
        </div>
      ) : staging.failed ? (
        <LoadFailed description={t("loadFailed")} />
      ) : (
        <StagingPanel
          appId={id}
          production={application}
          staging={staging.staging}
          canManage={canManage}
        />
      )}
    </div>
  );
}
