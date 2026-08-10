import { notFound, redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { PageHeader } from "@/components/ui/page-header";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getApplication, getApplications, getSiteTypes } from "@/lib/applications/get-applications";
import { CloneApplicationPanel } from "@/components/applications/clone/clone-panel";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

export async function generateMetadata({ params }) {
  const { application } = await params;
  const [t, result] = await Promise.all([
    getTranslations("applications.clone"),
    getApplication(application),
  ]);
  return { title: `${t("pageTitle")} — ${result.application?.name ?? ""}` };
}

export default async function CloneApplicationPage({ params }) {
  const { application: id } = await params;
  const [permissions, appPermissions, t, result] = await Promise.all([
    getPermissions(),
    getPermissions("application", id).catch(() => []),
    getTranslations("applications.clone"),
    getApplication(id),
  ]);

  if (!can(permissions, "application", "view")) redirect("/dashboard");
  if (result.status === 404) notFound();
  if (result.failed || !result.application) return <LoadFailed description={t("loadFailed")} />;

  // Granted per site type, the same contract as every other application
  // screen: no grant here means this screen should not exist for this site.
  if (!can(appPermissions, "app_clone", "view", "application")) {
    redirect(`/applications/${id}`);
  }

  const application = result.application;
  const canManage = can(appPermissions, "app_clone", "manage", "application");

  // Needed only to answer "can this type be cloned at all" — a type that needs
  // a database and has no recipe is refused by the backend, and the screen says
  // so up front rather than after someone types a domain. Cached per request.
  const [{ siteTypes }, { applications }] = await Promise.all([getSiteTypes(), getApplications()]);
  const siteType = siteTypes.find((type) => type.name === application.site_type) ?? null;

  // Copies already made from this site. No endpoint needed — every application
  // carries the id it was cloned from, and this list is cached per request.
  const copies = applications.filter(
    (candidate) => Number(candidate.cloned_from_application_id) === Number(id),
  );

  return (
    <div className="space-y-6">
      <PageHeader
        title={t("pageTitle")}
        subtitle={t("pageSubtitle", { name: application.name })}
      />

      <CloneApplicationPanel
        application={application}
        siteType={siteType}
        copies={copies}
        takenDomains={applications.map((a) => a.domain).filter(Boolean)}
        canManage={canManage}
      />
    </div>
  );
}
