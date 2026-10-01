import { PageHeader } from "@/components/ui/page-header";
import { notFound, redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getApplication } from "@/lib/applications/get-applications";
import { getWebhookProviders } from "@/lib/applications/get-webhook-providers";
import { getGitAccounts } from "@/lib/git/get-git";
import { getDeployments } from "@/lib/applications/get-deployments";
import { gitProviderFromUrl } from "@/lib/applications/git-provider-from-url";
import { DeploymentPanel } from "@/components/applications/deployment/deployment-panel";
import { LoadFailed } from "@/components/data-table/load-failed";
import { PermissionDenied } from "@/components/sections/permission-denied";
import { isSettled } from "@/lib/applications/settled";

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

  if (!can(permissions, "application", "view")) return <PermissionDenied title={t("pageTitle")} />;
  // Site deleted: land on the list, which explains why on arrival.
  if (result.status === 404) redirect("/applications?gone=1");
  if (result.failed || !result.application)
    return <LoadFailed description={t("loadFailed")} status={result.status} failure={result.failure} message={result.message} debug={result.debug} />;

  const application = result.application;
  // Git sites only: the deploy endpoint 404s for anything else. Checked before
  // the grant because the API drops `app_deployment` for non-git sites.
  const isGit = Boolean(application.repository || application.repository_url);
  if (!isGit) notFound();
  // Deployment is its own grant, separate from the server-level `application`.
  if (!can(appPermissions, "app_deployment", "view", "application")) {
    return <PermissionDenied title={t("pageTitle")} />;
  }

  const canManage = can(appPermissions, "app_deployment", "manage", "application");
  // Logs is a separate grant; without it the failure banner offers no log link.
  const canViewLogs = can(appPermissions, "app_log", "view", "application");
  const [{ providers }, history, gitAccounts] = await Promise.all([
    getWebhookProviders(),
    // A failure here must not hide the Deploy button; the panel renders without history.
    getDeployments(id),
    // The application payload carries `git_account_id` but no provider name;
    // needed to narrow the webhook providers. Also fetched when the account is
    // gone, so re-linking can offer the remaining ones.
    application.git_account_id || application.git_account_missing
      ? getGitAccounts().then((r) => r.accounts ?? []).catch(() => [])
      : Promise.resolve([]),
  ]);

  // Linked account first, then the repository URL (github.com, gitlab.com,
  // bitbucket.org). Null when neither can tell: the full picker is shown.
  const gitProvider =
    gitAccounts.find((a) => a.id === application.git_account_id)?.provider ??
    gitProviderFromUrl(application.repository_url) ??
    null;

  // Only the provider this site deploys from; the full list when it is unknown.
  const webhookProviders =
    gitProvider && providers.some((p) => p.name === gitProvider)
      ? providers.filter((p) => p.name === gitProvider)
      : providers;

  // A site with recorded (even failed) deploys has run, so skip the waiting message.
  const settled = isSettled(application) || history.deployments.length > 0;

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
          providers={webhookProviders}
          canManage={canManage}
          canViewLogs={canViewLogs}
          deployments={history.deployments}
          settings={history.settings}
          history={history}
          gitAccounts={gitAccounts}
        />
      )}
    </div>
  );
}
