import { PageHeader } from "@/components/ui/page-header";
import { notFound, redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { TriangleAlert } from "lucide-react";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import {
  getApplicationWaf,
  getServerCapabilities,
  getWafOptions,
} from "@/lib/applications/get-applications";
import { FirewallSection } from "@/components/applications/firewall/firewall-section";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

export async function generateMetadata({ params }) {
  const { application } = await params;
  const [t, result] = await Promise.all([
    getTranslations("applications.firewall"),
    getApplicationWaf(application),
  ]);
  return { title: `${t("pageTitle")} — ${result.application?.name ?? ""}` };
}

export default async function ApplicationFirewallPage({ params }) {
  const { application: id } = await params;
  const [permissions, appPermissions, t, result] = await Promise.all([
    getPermissions(),
    getPermissions("application", id).catch(() => []),
    getTranslations("applications.firewall"),
    // Not getApplication: the exceptions and custom rules are `whenLoaded` on
    // the backend and come back from this endpoint only.
    getApplicationWaf(id),
  ]);

  if (!can(permissions, "application", "view")) redirect("/dashboard");
  if (result.status === 404) notFound();
  if (result.failed || !result.application) return <LoadFailed description={t("loadFailed")} />;

  const application = result.application;
  if (!can(appPermissions, "app_firewall", "view", "application")) {
    redirect(`/applications/${id}`);
  }
  const canManage = can(appPermissions, "app_firewall", "manage", "application");
  const settled = application.status === "active";

  // The category and mode labels come from the API; without them there is
  // nothing truthful to render, so a failure there is a load failure. The web
  // server is a separate, non-fatal question — if it can't be determined, the
  // screen just doesn't claim anything about OpenLiteSpeed.
  const [{ categories, modes, failed: optionsFailed }, { webServer }] = settled
    ? await Promise.all([getWafOptions(), getServerCapabilities()])
    : [{ categories: [], modes: [], failed: false }, { webServer: null }];

  const unsupported = webServer === "openlitespeed";

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
      ) : optionsFailed || categories.length === 0 || modes.length === 0 ? (
        <LoadFailed description={t("loadFailed")} />
      ) : (
        <>
          {/* Stated rather than hidden: on OpenLiteSpeed the settings still
              save, but nothing enforces them yet. A screen that quietly does
              nothing is worse than one that admits it. */}
          {unsupported ? (
            <div className="flex max-w-4xl items-start gap-2.5 rounded-xl border border-warning/40 bg-warning/10 p-4 text-sm">
              <TriangleAlert className="mt-0.5 size-4 shrink-0 text-warning" />
              <p>{t("openlitespeed")}</p>
            </div>
          ) : null}
          <FirewallSection
            appId={id}
            application={application}
            categories={categories}
            modes={modes}
            canManage={canManage}
          />
        </>
      )}
    </div>
  );
}
