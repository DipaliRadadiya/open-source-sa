import { PageHeader } from "@/components/ui/page-header";
import { notFound, redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getApplication } from "@/lib/applications/get-applications";
import { getWebhookProviders } from "@/lib/applications/get-webhook-providers";
import { DeploymentPanel } from "@/components/applications/deployment/deployment-panel";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

export async function generateMetadata({ params }) {
  const { application } = await params;
  const [t, result] = await Promise.all([
    getTranslations("applications.deployment"),
    getApplication(application),
  ]);
  return { title: `${t("pageTitle")} — ${result.application?.name ?? ""}` };
}

export default async function ApplicationDeploymentPage({ params }) {
  const { application: id } = await params;
  const [permissions, appPermissions, t, result] = await Promise.all([
    getPermissions(),
    getPermissions("application", id).catch(() => []),
    getTranslations("applications.deployment"),
    getApplication(id),
  ]);

  if (!can(permissions, "application", "view")) redirect("/dashboard");
  if (result.status === 404) notFound();
  if (result.failed || !result.application)
    return <LoadFailed description={t("loadFailed")} />;

  const application = result.application;
  // Deployment is its own grant, separate from the server-level `application`.
  if (!can(appPermissions, "app_deployment", "view", "application")) {
    redirect(`/applications/${id}`);
  }
  // Git sites only — the deploy endpoint 404s for anything else and the sidebar
  // hides the item, so a hand-typed URL for a non-git site is simply not found.
  const isGit = Boolean(application.repository || application.repository_url);
  if (!isGit) notFound();

  const canManage = can(appPermissions, "app_deployment", "manage", "application");
  const settled = application.status === "active";
  const { providers } = await getWebhookProviders();

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
      ) : (
        <DeploymentPanel
          application={application}
          providers={providers}
          canManage={canManage}
        />
      )}
    </div>
  );
}
