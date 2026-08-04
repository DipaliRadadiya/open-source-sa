import { getPermissions } from "@/lib/permissions/get-permissions";
import { ApplicationNav } from "@/components/sections/application-nav";

/**
 * Exists for one reason: the sidebar sits in the `(app)` layout and never sees
 * the `[application]` param, so it cannot ask for that site's menu. This layout
 * can, and hands it over.
 */
export default async function ApplicationLayout({ children, params }) {
  const { application } = await params;
  // A fixture id has no record behind it, so the request would 422. The preview
  // keeps the server menu it already has.
  const items = Number.isFinite(Number(application))
    ? await getPermissions("application", application).catch(() => null)
    : null;

  return (
    <>
      {items ? <ApplicationNav items={items} /> : null}
      {children}
    </>
  );
}
