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
  // The site is gone. Land on the list — the only place left to go — and say
  // why on arrival, rather than parking on a dead end that offers one link.
  if (result.status === 404) redirect("/applications?gone=1");
  if (result.failed || !result.application)
    return <LoadFailed description={t("loadFailed")} status={result.status} failure={result.failure} message={result.message} debug={result.debug} />;

  const application = result.application;
  // Deployment is its own grant, separate from the server-level `application`.
  if (!can(appPermissions, "app_deployment", "view", "application")) {
    return <PermissionDenied title={t("pageTitle")} />;
  }
  // Git sites only — the deploy endpoint 404s for anything else and the sidebar
  // hides the item, so a hand-typed URL for a non-git site is simply not found.
  const isGit = Boolean(application.repository || application.repository_url);
  if (!isGit) notFound();

  const canManage = can(appPermissions, "app_deployment", "manage", "application");
  // The failure banner offers the site's own log as the evidence, and the Logs
  // page is a separate grant — offering a link that would only redirect them
  // back here is worse than offering nothing.
  const canViewLogs = can(appPermissions, "app_log", "view", "application");
  const settled = application.status === "active";
  const [{ providers }, history, gitAccounts] = await Promise.all([
    getWebhookProviders(),
    // History and settings arrive together; a failure here must not blank the
    // Deploy button, so the panel simply renders without them.
    getDeployments(id),
    // Only to learn which provider this site's account belongs to. The
    // application payload carries `git_account_id` and no provider name, and
    // without it the webhook card offers all three as if the choice were open
    // — it is not: a GitHub site can only ever be pushed to by GitHub.
    application.git_account_id
      ? getGitAccounts().then((r) => r.accounts ?? []).catch(() => [])
      : Promise.resolve([]),
  ]);

  /*
   * The account first, then the repository URL, then ask.
   *
   * A linked account states its provider outright. A public repository has no
   * account — but for github.com, gitlab.com and bitbucket.org the URL on this
   * same screen already answers it, and the card was asking anyway. Null only
   * when neither can say: an unlinked site on a self-hosted host, or one whose
   * account has gone. Then the full picker comes back, which is the only honest
   * thing left.
   */
  const gitProvider =
    gitAccounts.find((a) => a.id === application.git_account_id)?.provider ??
    gitProviderFromUrl(application.repository_url) ??
    null;

  /*
   * Only the provider this site actually deploys from.
   *
   * The card offered all three and asked which one — but the answer was never
   * open: a site connected to a GitHub account can only ever be pushed to by
   * GitHub, and picking Bitbucket there produces setup instructions for a
   * webhook nobody will ever send. Narrowing the list is what turns a question
   * into the answer.
   *
   * Falls back to the full list when the provider is unknown — an unlinked
   * site, or one whose account has gone. Guessing there would be worse than
   * asking.
   */
  const webhookProviders =
    gitProvider && providers.some((p) => p.name === gitProvider)
      ? providers.filter((p) => p.name === gitProvider)
      : providers;

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
        />
      )}
    </div>
  );
}
