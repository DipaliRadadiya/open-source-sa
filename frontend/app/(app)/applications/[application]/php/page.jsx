import { notFound, redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { PageHeader } from "@/components/ui/page-header";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getApplication, getApplicationPhp, getSiteTypes } from "@/lib/applications/get-applications";
import { getTimezones } from "@/lib/settings/get-timezones";
import { PhpPanel } from "@/components/applications/php/php-panel";
import { LoadFailed } from "@/components/data-table/load-failed";
import { PermissionDenied } from "@/components/sections/permission-denied";
import { isSettled } from "@/lib/applications/settled";

export const dynamic = "force-dynamic";

export async function generateMetadata({ params }) {
  const { application } = await params;
  const [t, result] = await Promise.all([
    getTranslations("applications.php"),
    getApplication(application),
  ]);
  return { title: `${t("pageTitle")} — ${result.application?.name ?? ""}` };
}

export default async function ApplicationPhpPage({ params }) {
  const { application: id } = await params;
  const [permissions, appPermissions, t, result] = await Promise.all([
    getPermissions(),
    getPermissions("application", id).catch(() => []),
    getTranslations("applications.php"),
    getApplication(id),
  ]);

  if (!can(permissions, "application", "view")) return <PermissionDenied title={t("pageTitle")} />;
  // The site is gone: back to the list, which explains the redirect.
  if (result.status === 404) redirect("/applications?gone=1");
  if (result.failed || !result.application) return <LoadFailed description={t("loadFailed")} status={result.status} failure={result.failure} message={result.message} debug={result.debug} />;

  const application = result.application;
  if (!can(appPermissions, "app_php", "view", "application")) {
    return <PermissionDenied title={t("pageTitle")} />;
  }

  const canManage = can(appPermissions, "app_php", "manage", "application");
  const settled = isSettled(application);

  // The PHP settings drive the whole screen, so their failure is a load
  // failure. Timezones and site types are optional and degrade quietly.
  const [phpResult, timezones, catalogue] = settled
    ? await Promise.all([
        getApplicationPhp(id),
        getTimezones().catch(() => []),
        // Only for the version range below.
        getSiteTypes().catch(() => ({ siteTypes: [] })),
      ])
    : [null, [], { siteTypes: [] }];

  // PHP versions the site's application supports; the API only checks that a
  // version is installed, not that the application runs on it.
  const phpRange =
    (catalogue.siteTypes ?? []).find((type) => type.name === application.site_type)
      ?.php_version_range ?? null;

  // 404 means the site type does not serve PHP, so this screen does not apply.
  if (phpResult?.status === 404) notFound();

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
      ) : phpResult.failed || !phpResult.php ? (
        <LoadFailed description={t("loadFailed")} status={phpResult.status} failure={phpResult.failure} message={phpResult.message} debug={phpResult.debug} />
      ) : (
        <PhpPanel
          appId={id}
          php={phpResult.php}
          phpRange={phpRange}
          siteTypeTitle={application.site_type_title ?? application.site_type ?? ""}
          // The folder above public_html, where prepend files often live.
          applicationPath={(application.path ?? "").replace(/\/public_html\/?$/, "")}
          timezones={timezones}
          canManage={canManage}
        />
      )}
    </div>
  );
}
