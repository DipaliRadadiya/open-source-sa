import { getPermissions } from "@/lib/permissions/get-permissions";
import { ApplicationNav } from "@/components/sections/application-nav";

/**
 * Exists for one reason: the sidebar sits in the `(app)` layout and never sees
 * the `[application]` param, so it cannot ask for that site's menu. This layout
 * can, and hands it over.
 */
export default async function ApplicationLayout({ children, params }) {
  const { application } = await params;
  const items = await getPermissions("application", application).catch(() => null);

  return (
    <>
      {items ? <ApplicationNav items={items} /> : null}
      {children}
    </>
  );
}
