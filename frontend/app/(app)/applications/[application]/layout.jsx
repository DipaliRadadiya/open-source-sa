import { getPermissions } from "@/lib/permissions/get-permissions";
import { getApplication } from "@/lib/applications/get-applications";
import { ApplicationNav } from "@/components/sections/application-nav";
import { PageCrumb } from "@/components/sections/page-crumb";
import { SystemUserMissing } from "@/components/applications/system-user-missing";
import { can } from "@/lib/permissions/can";

/**
 * Exists for one reason: the sidebar sits in the `(app)` layout and never sees
 * the `[application]` param, so it cannot ask for that site's menu. This layout
 * can, and hands it over — along with the site's name for the breadcrumb, so no
 * screen inside the site can forget to say which site you are in.
 */
export default async function ApplicationLayout({ children, params }) {
  const { application } = await params;
  const [items, result, permissions] = await Promise.all([
    getPermissions("application", application).catch(() => null),
    getApplication(application).catch(() => null),
    getPermissions().catch(() => []),
  ]);
  const name = result?.application?.name;
  // Null, not absent: the site's own endpoint always loads the user, so null
  // means it is gone and every other route for this site answers 409.
  const orphaned = result?.application?.system_user === null;

  return (
    <>
      {/* Always reported, even as null — "this site has no menu" is exactly the
          fact the sidebar needs when the site is gone. */}
      <ApplicationNav items={items} application={result?.application ?? null} />
      {name ? <PageCrumb href={`/applications/${application}`}>{name}</PageCrumb> : null}
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
