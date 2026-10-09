import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { PageHeader } from "@/components/ui/page-header";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { getCurrentUser } from "@/lib/auth/get-current-user";
import { can } from "@/lib/permissions/can";
import { getApplication, getApplicationStaging, getStagingSafetyCopies } from "@/lib/applications/get-applications";
import { getBackupTarget } from "@/lib/backups/get-backups";
import { StagingPanel } from "@/components/applications/staging/staging-panel";
import { LoadFailed } from "@/components/data-table/load-failed";
import { PermissionDenied } from "@/components/sections/permission-denied";
import { isSettled } from "@/lib/applications/settled";

export const dynamic = "force-dynamic";

export async function generateMetadata({ params }) {
  const { application } = await params;
  const [t, result] = await Promise.all([
    getTranslations("applications.staging"),
    getApplication(application),
  ]);
  return { title: `${t("pageTitle")} — ${result.application?.name ?? ""}` };
}

export default async function ApplicationStagingPage({ params }) {
  const { application: id } = await params;
  const [permissions, appPermissions, t, result] = await Promise.all([
    getPermissions(),
    getPermissions("application", id).catch(() => []),
    getTranslations("applications.staging"),
    getApplication(id),
  ]);

  if (!can(permissions, "application", "view")) return <PermissionDenied title={t("pageTitle")} />;
  // The site is gone: back to the list, which explains why.
  if (result.status === 404) redirect("/applications?gone=1");
  if (result.failed || !result.application) return <LoadFailed description={t("loadFailed")} status={result.status} failure={result.failure} message={result.message} debug={result.debug} />;

  const application = result.application;
  if (!can(appPermissions, "app_staging", "view", "application")) {
    // The grant exists only for WordPress; for an administrator a missing
    // grant means "not this site type", not a permission problem.
    if ((await getCurrentUser().catch(() => null))?.is_admin) return <TypeNotSupported application={application} t={t} />;
    return <PermissionDenied title={t("pageTitle")} />;
  }

  const canManage = can(appPermissions, "app_staging", "manage", "application");
  // Removing the copy is an application delete, gated as the API gates it.
  const canDelete = can(permissions, "application", "manage");
  const settled = isSettled(application);

  const staging = settled ? await getApplicationStaging(id) : null;
  if (staging?.status === 403) return <PermissionDenied title={t("pageTitle")} />;

  // Backing up as part of the push is the backup feature's power, and needs a target.
  const canBackUp = can(appPermissions, "app_backup", "manage", "application");
  const hasCopy = Boolean(staging?.staging);
  const [{ target }, safety] = await Promise.all([
    hasCopy && canManage && canBackUp ? getBackupTarget(id) : Promise.resolve({ target: null }),
    canManage ? getStagingSafetyCopies(id) : Promise.resolve({ copies: [] }),
  ]);

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
      ) : staging.status === 404 ? (
        // Staging is WordPress-only; other site types answer 404, which is not an error.
        <Unsupported>{t("unsupported", { type: application.site_type_title ?? application.site_type })}</Unsupported>
      ) : staging.failed ? (
        <LoadFailed description={t("loadFailed")} status={staging.status} failure={staging.failure} message={staging.message} debug={staging.debug} />
      ) : (
        <StagingPanel
          appId={id}
          production={application}
          staging={staging.staging}
          canManage={canManage}
          canDelete={canDelete}
          canBackUp={canBackUp && Boolean(target)}
          safetyCopies={safety.copies}
        />
      )}
    </div>
  );
}

function Unsupported({ children }) {
  return <div className="rounded-2xl border bg-muted/30 p-6 text-sm text-muted-foreground">{children}</div>;
}

function TypeNotSupported({ application, t }) {
  return (
    <div className="space-y-6">
      <PageHeader title={t("pageTitle")} subtitle={t("pageSubtitle")} />
      <Unsupported>{t("unsupported", { type: application.site_type_title ?? application.site_type })}</Unsupported>
    </div>
  );
}
