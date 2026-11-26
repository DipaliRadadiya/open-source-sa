import { getPermissions } from "@/lib/permissions/get-permissions";
import { getApplication, getSiteTypes } from "@/lib/applications/get-applications";
import { applicationIconDataUri } from "@/lib/applications/application-icon";
import { ApplicationNav } from "@/components/sections/application-nav";
import { PageCrumb } from "@/components/sections/page-crumb";
import { SystemUserMissing } from "@/components/applications/system-user-missing";
import { ApplicationStatusWatcher } from "@/components/applications/application-status-watcher";
import { ReloadPendingNotice } from "@/components/applications/reload-pending-notice";
import { can } from "@/lib/permissions/can";
import { getGitAccounts } from "@/lib/git/get-git";
import { gitProviderFor, providersByAccountId } from "@/lib/applications/git-provider";

// The (app) sidebar never gets the [application] param, so this layout adds the site menu.
export default async function ApplicationLayout({ children, params }) {
  const { application } = await params;
  const [items, result, permissions] = await Promise.all([
    getPermissions("application", application).catch(() => null),
    getApplication(application).catch(() => null),
    getPermissions().catch(() => []),
  ]);
  const name = result?.application?.name;
  const orphaned = result?.application?.system_user === null;
  const gitAccounts = result?.application?.git_account_id
    ? await getGitAccounts().then((r) => r.accounts ?? []).catch(() => [])
    : [];
  const gitProvider = gitProviderFor(result?.application, providersByAccountId(gitAccounts));
  const iconDataUri = result?.application?.serving_profile === "docker"
    ? applicationIconDataUri(result.application, (await getSiteTypes().catch(() => null))?.siteTypes ?? [])
    : null;

  return (
    <>
      <ApplicationNav items={items} application={result?.application ?? null} gitProvider={gitProvider} iconDataUri={iconDataUri} />
      {name ? <PageCrumb href={`/applications/${application}`}>{name}</PageCrumb> : null}
      {result?.application ? (
        <ApplicationStatusWatcher
          id={result.application.id}
          status={result.application.status}
          redeploy={result.application.container_redeploy ?? null}
        />
      ) : null}
      <ReloadPendingNotice appId={application} className="mb-6" />
      {orphaned ? (
        <SystemUserMissing
          application={result.application}
          canDelete={can(permissions, "application", "manage")}
        />
      ) : (
        children
      )}
    </>
  );
}
