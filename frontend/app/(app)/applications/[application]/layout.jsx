import { getPermissions } from "@/lib/permissions/get-permissions";
import { getApplication } from "@/lib/applications/get-applications";
import { ApplicationNav } from "@/components/sections/application-nav";
import { PageCrumb } from "@/components/sections/page-crumb";

/**
 * Exists for one reason: the sidebar sits in the `(app)` layout and never sees
 * the `[application]` param, so it cannot ask for that site's menu. This layout
 * can, and hands it over — along with the site's name for the breadcrumb, so no
 * screen inside the site can forget to say which site you are in.
 */
export default async function ApplicationLayout({ children, params }) {
  const { application } = await params;
  const [items, result] = await Promise.all([
    getPermissions("application", application).catch(() => null),
    getApplication(application).catch(() => null),
  ]);
  const name = result?.application?.name;

  return (
    <>
      {items ? <ApplicationNav items={items} /> : null}
      {name ? <PageCrumb href={`/applications/${application}`}>{name}</PageCrumb> : null}
      {children}
    </>
  );
}
