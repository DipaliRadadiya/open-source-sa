import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { PageHeader } from "@/components/ui/page-header";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getApplication, getSiteTypes } from "@/lib/applications/get-applications";
import { getStorageDestinations } from "@/lib/storage/get-storage";
import { cookies } from "next/headers";
import { getActiveRestore, getBackupTarget, getApplicationBackups, getBackupTargetOptions } from "@/lib/backups/get-backups";
import { DISMISSED_RESTORES_COOKIE, parseDismissedRestores } from "@/lib/backups/dismissed-restores";
import { getDatabaseCounts, getApplicationDatabases, getEngines, getUnattachedDatabases } from "@/lib/databases/get-databases";
import { siteNeedsDatabase } from "@/lib/backups/database-availability";
import { BackupsPanel } from "@/components/applications/backups/backups-panel";
import { LoadFailed } from "@/components/data-table/load-failed";
import { PermissionDenied } from "@/components/sections/permission-denied";
import { isSettled } from "@/lib/applications/settled";

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

  if (!can(permissions, "application", "view")) return <PermissionDenied title={t("pageTitle")} />;
  if (result.status === 404) redirect("/applications?gone=1");
  if (result.failed || !result.application)
    return <LoadFailed description={t("loadFailed")} status={result.status} failure={result.failure} message={result.message} debug={result.debug} />;

  if (!can(appPermissions, "app_backup", "view", "application")) {
    return <PermissionDenied title={t("pageTitle")} />;
  }

  const application = result.application;
  const canManage = can(appPermissions, "app_backup", "manage", "application");
  const canRestore = can(permissions, "backup", "manage");
  const canManageDatabases = can(permissions, "database", "manage");
  const settled = isSettled(application);

  const [{ target, hasVolumes }, { destinations }, { backups, meta, failed: backupsFailed, status: backupsStatus }, activeRestore, databases, siteDbs, spareDbs, engineList, siteTypes, { options: backupOptions }] = await Promise.all([
    settled ? getBackupTarget(id) : Promise.resolve({ target: null, hasVolumes: null }),
    getStorageDestinations(),
    settled
      ? getApplicationBackups(id, { per_page: 5 })
      : Promise.resolve({ backups: [], meta: { total: 0 } }),
    settled && canRestore
      ? getActiveRestore(id, {
          dismissed: parseDismissedRestores((await cookies()).get(DISMISSED_RESTORES_COOKIE)?.value),
        })
      : Promise.resolve(null),
    settled ? getDatabaseCounts() : Promise.resolve({ counts: null, known: false }),
    settled && canManageDatabases
      ? getApplicationDatabases(id)
      : Promise.resolve({ databases: [], failed: false }),
    settled && canManageDatabases ? getUnattachedDatabases() : Promise.resolve({ databases: [] }),
    settled && canManageDatabases ? getEngines() : Promise.resolve({ engines: [] }),
    settled && canManageDatabases
      ? getSiteTypes().catch(() => ({ siteTypes: [] }))
      : Promise.resolve({ siteTypes: [] }),
    settled ? getBackupTargetOptions() : Promise.resolve({ options: null }),
  ]);

  const everyBackup =
    (canRestore || canManage) && !backupsFailed && meta.total > backups.length
      ? (await getApplicationBackups(id, { per_page: 100 })).backups
      : backups;
  const archiveDestinations =
    canRestore && meta.total > backups.length ? everyBackup.map((backup) => backup.storage_destination_name) : [];
  const keptCount =
    backupsFailed || meta.total > everyBackup.length
      ? null
      : everyBackup.filter((backup) => backup.status === "verified" && !backup.is_safety).length;

  const needsDatabase = siteNeedsDatabase(siteTypes.siteTypes, application.site_type);

  return (
    <div className="space-y-6">
      <PageHeader title={t("pageTitle")} />

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
          archiveDestinations={archiveDestinations}
          keptCount={keptCount}
          total={meta.total}
          backupsFailed={backupsFailed}
          backupsForbidden={backupsFailed && backupsStatus === 403}
          siteDatabasesKnown={!siteDbs.failed}
          databaseCounts={databases.counts}
          databasesKnown={databases.known}
          siteTypes={siteTypes.siteTypes}
          activeRestore={activeRestore}
          canManage={canManage}
          canRestore={canRestore}
          canTurnOff={canRestore}
          siteDatabases={siteDbs.databases}
          unattachedDatabases={spareDbs.databases}
          engines={engineList.engines}
          needsDatabase={needsDatabase}
          canManageDatabases={canManageDatabases}
          backupOptions={backupOptions}
          hasVolumes={hasVolumes}
        />
      )}
    </div>
  );
}
