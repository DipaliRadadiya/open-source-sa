import { getPermissions } from "@/lib/permissions/get-permissions";
import { getApplication } from "@/lib/applications/get-applications";
import { ApplicationNav } from "@/components/sections/application-nav";
import { PageCrumb } from "@/components/sections/page-crumb";
import { SystemUserMissing } from "@/components/applications/system-user-missing";
import { ApplicationStatusWatcher } from "@/components/applications/application-status-watcher";
import { can } from "@/lib/permissions/can";
import { getGitAccounts } from "@/lib/git/get-git";
import { gitProviderFor, providersByAccountId } from "@/lib/applications/git-provider";

// The `(app)` sidebar never sees the `[application]` param, so this layout supplies the site's menu and crumb.
export default async function ApplicationLayout({ children, params }) {
  const { application } = await params;
  const [items, result, permissions] = await Promise.all([
    getPermissions("application", application).catch(() => null),
    getApplication(application).catch(() => null),
    getPermissions().catch(() => []),
  ]);
  const name = result?.application?.name;
  // Null, not absent: null means the system user is gone (other routes answer 409).
  const orphaned = result?.application?.system_user === null;
  // Only account-linked git sites need the accounts list; cached for the dashboard page.
  const gitAccounts = result?.application?.git_account_id
    ? await getGitAccounts().then((r) => r.accounts ?? []).catch(() => [])
    : [];
  const gitProvider = gitProviderFor(result?.application, providersByAccountId(gitAccounts));

  return (
    <>
      {/* Always reported, even as null: the sidebar needs to know the site has no menu. */}
      <ApplicationNav items={items} application={result?.application ?? null} gitProvider={gitProvider} />
      {name ? <PageCrumb href={`/applications/${application}`}>{name}</PageCrumb> : null}
      {result?.application ? (
        <ApplicationStatusWatcher id={result.application.id} status={result.application.status} />
      ) : null}
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
