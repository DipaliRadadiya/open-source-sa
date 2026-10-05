import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { PageHeader } from "@/components/ui/page-header";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getApplication, getSiteTypes } from "@/lib/applications/get-applications";
import { getStorageDestinations } from "@/lib/storage/get-storage";
import { cookies } from "next/headers";
import { getActiveRestore, getBackupTarget, getBackupTargetOptions, getBackups } from "@/lib/backups/get-backups";
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
  // The site is gone: back to the list, which explains the redirect.
  if (result.status === 404) redirect("/applications?gone=1");
  if (result.failed || !result.application)
    return <LoadFailed description={t("loadFailed")} status={result.status} failure={result.failure} message={result.message} debug={result.debug} />;

  // Granted per site type, like the other application screens.
  if (!can(appPermissions, "app_backup", "view", "application")) {
    return <PermissionDenied title={t("pageTitle")} />;
  }

  const application = result.application;
  const canManage = can(appPermissions, "app_backup", "manage", "application");
  // Restoring needs server-level `backup` manage, not this site's `app_backup`.
  const canRestore = can(permissions, "backup", "manage");
  // Attaching is a server-level database grant, not this site's backup grant.
  const canManageDatabases = can(permissions, "database", "manage");
  const settled = isSettled(application);

  // A provisioning site has nothing to back up. `meta.total` is the whole history; the list is capped at five.
  // `backupsFailed` keeps "could not ask" distinct from "nothing has run".
  const [{ target }, { destinations }, { backups, meta, failed: backupsFailed, status: backupsStatus }, activeRestore, databases, siteDbs, spareDbs, engineList, siteTypes, { options: backupOptions }] = await Promise.all([
    settled ? getBackupTarget(id) : Promise.resolve({ target: null }),
    getStorageDestinations(),
    settled
      ? getBackups({ application: id, per_page: 5 })
      : Promise.resolve({ backups: [], meta: { total: 0 } }),
    // Seeded from the server so a reload still shows a running restore.
    settled && canRestore
      ? getActiveRestore(id, {
          dismissed: parseDismissedRestores((await cookies()).get(DISMISSED_RESTORES_COOKIE)?.value),
        })
      : Promise.resolve(null),
    // Whether a database backup would hold anything; a failure leaves it unknown.
    settled ? getDatabaseCounts() : Promise.resolve({ counts: null, known: false }),
    // For the "no database" notice and Attach action; `failed` is passed on so
    // a failed read never claims the site has no database.
    settled && canManageDatabases
      ? getApplicationDatabases(id)
      : Promise.resolve({ databases: [], failed: false }),
    settled && canManageDatabases ? getUnattachedDatabases() : Promise.resolve({ databases: [] }),
    settled && canManageDatabases ? getEngines() : Promise.resolve({ engines: [] }),
    settled && canManageDatabases
      ? getSiteTypes().catch(() => ({ siteTypes: [] }))
      : Promise.resolve({ siteTypes: [] }),
    // Form choices, including which picker each frequency uses.
    settled ? getBackupTargetOptions() : Promise.resolve({ options: null }),
  ]);

  // The list above is only the newest five. Turn off names every storage holding an
  // archive, and lowering "keep" must say how many it really deletes.
  const everyBackup =
    (canRestore || canManage) && !backupsFailed && meta.total > backups.length
      ? (await getBackups({ application: id, per_page: 100 })).backups
      : backups;
  const archiveDestinations =
    canRestore && meta.total > backups.length ? everyBackup.map((backup) => backup.storage_destination_name) : [];
  // What retention counts (RetentionEnforcer): complete, non-safety copies. Null when unseen.
  const keptCount =
    backupsFailed || meta.total > everyBackup.length
      ? null
      : everyBackup.filter((backup) => backup.status === "verified" && !backup.is_safety).length;

  // Only site types that declare `needs_database` get the warning.
  const needsDatabase = siteNeedsDatabase(siteTypes.siteTypes, application.site_type);

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
        />
      )}
    </div>
  );
}
