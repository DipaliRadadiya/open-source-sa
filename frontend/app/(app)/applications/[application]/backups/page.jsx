import { notFound, redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { PageHeader } from "@/components/ui/page-header";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getApplication } from "@/lib/applications/get-applications";
import { getStorageDestinations } from "@/lib/storage/get-storage";
import { getActiveRestore, getBackupTarget, getBackups } from "@/lib/backups/get-backups";
import { BackupsPanel } from "@/components/applications/backups/backups-panel";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

export async function generateMetadata({ params }) {
  const { application } = await params;
  const [t, result] = await Promise.all([
    getTranslations("backups.application"),
    getApplication(application),
  ]);
  return { title: `${t("pageTitle")} — ${result.application?.name ?? ""}` };
}

export default async function ApplicationBackupsPage({ params }) {
  const { application: id } = await params;
  const [permissions, appPermissions, t, result] = await Promise.all([
    getPermissions(),
    getPermissions("application", id).catch(() => []),
    getTranslations("backups.application"),
    getApplication(id),
  ]);

  if (!can(permissions, "application", "view")) redirect("/dashboard");
  if (result.status === 404) notFound();
  if (result.failed || !result.application)
    return <LoadFailed description={t("loadFailed")} />;

  // Granted per site type, the same contract as the other application screens:
  // no grant here means this screen should not exist for this site.
  if (!can(appPermissions, "app_backup", "view", "application")) {
    redirect(`/applications/${id}`);
  }

  const application = result.application;
  const canManage = can(appPermissions, "app_backup", "manage", "application");
  // Restoring needs `backup` at manage level — a different trust from
  // configuring a schedule, so it is checked against the server-level catalog
  // rather than this site's `app_backup` grant.
  const canRestore = can(permissions, "backup", "manage");
  const settled = application.status === "active";

  // A site still provisioning has nothing to back up and no directory to point
  // at — offering the form would be offering a save that cannot work.
  const [{ target }, { destinations }, { backups }, activeRestore] = await Promise.all([
    settled ? getBackupTarget(id) : Promise.resolve({ target: null }),
    getStorageDestinations(),
    settled
      ? getBackups({ application: id, per_page: 5 })
      : Promise.resolve({ backups: [] }),
    // Seeded from the server so a reload — or a colleague's browser — still
    // shows a restore that is rewriting this site right now.
    settled && canRestore ? getActiveRestore(id) : Promise.resolve(null),
  ]);

  return (
    <div className="space-y-6">
      <PageHeader
        backHref="/applications"
        backLabel={t("back")}
        title={t("pageTitle")}
        subtitle={t("pageSubtitle")}
      />

      {!settled ? (
        <div className="rounded-2xl border bg-muted/30 p-6 text-sm text-muted-foreground">
          {t("provisioning")}
        </div>
      ) : (
        <BackupsPanel
          application={application}
          target={target}
          destinations={destinations}
          backups={backups}
          activeRestore={activeRestore}
          canManage={canManage}
          canRestore={canRestore}
        />
      )}
    </div>
  );
}
