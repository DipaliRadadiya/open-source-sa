import { notFound, redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { PageHeader } from "@/components/ui/page-header";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getApplication, getApplicationPhp } from "@/lib/applications/get-applications";
import { getTimezones } from "@/lib/settings/get-timezones";
import { PhpPanel } from "@/components/applications/php/php-panel";
import { LoadFailed } from "@/components/data-table/load-failed";

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

  if (!can(permissions, "application", "view")) redirect("/dashboard");
  if (result.status === 404) notFound();
  if (result.failed || !result.application) return <LoadFailed description={t("loadFailed")} />;

  const application = result.application;
  if (!can(appPermissions, "app_php", "view", "application")) {
    redirect(`/applications/${id}`);
  }

  const canManage = can(appPermissions, "app_php", "manage", "application");
  const settled = application.status === "active";

  // The whole screen is rendered from this response — versions, isolation and
  // the memory budget all come from it — so a failure is a load failure, not
  // an empty form. The timezone list is a nicety by comparison: it fills one
  // Advanced picker, and losing it must not take the page down with it.
  const [phpResult, timezones] = settled
    ? await Promise.all([getApplicationPhp(id), getTimezones().catch(() => [])])
    : [null, []];

  // The permission middleware answers 404 for a site type that does not serve
  // PHP. That is an answer, not a fault: this screen should not exist there.
  if (phpResult?.status === 404) notFound();

  return (
    <div className="space-y-6">
      <PageHeader
        backHref={`/applications/${id}`}
        backLabel={t("back")}
        title={t("pageTitle")}
        subtitle={t("pageSubtitle")}
      />

      {!settled ? (
        <div className="rounded-2xl border bg-muted/30 p-6 text-sm text-muted-foreground">
          {t("provisioning")}
        </div>
      ) : phpResult.failed || !phpResult.php ? (
        <LoadFailed description={t("loadFailed")} />
      ) : (
        <PhpPanel
          appId={id}
          php={phpResult.php}
          timezones={timezones}
          canManage={canManage}
        />
      )}
    </div>
  );
}
