import { PageHeader } from "@/components/ui/page-header";
import { redirect } from "next/navigation";
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
import { DetectLogCard } from "@/components/applications/firewall/detect-log-card";
import { AutoRefresh } from "@/components/ui/auto-refresh";
import { getApplicationLog } from "@/lib/applications/get-application-logs";
import { parseDetectLog } from "@/lib/firewall/parse-detect-log";
import { LoadFailed } from "@/components/data-table/load-failed";
import { PermissionDenied } from "@/components/sections/permission-denied";
import { isSettled } from "@/lib/applications/settled";

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
    // Not getApplication: exceptions and custom rules are `whenLoaded` and
    // only returned by this endpoint.
    getApplicationWaf(id),
  ]);

  if (!can(permissions, "application", "view")) return <PermissionDenied title={t("pageTitle")} />;
  // The site is gone: back to the list, which explains why.
  if (result.status === 404) redirect("/applications?gone=1");
  // The firewall endpoint answers 403 for roles without Web Firewall, before
  // the permission check below can run.
  if (result.status === 403) return <PermissionDenied title={t("pageTitle")} />;
  if (result.failed || !result.application) return <LoadFailed description={t("loadFailed")} status={result.status} failure={result.failure} message={result.message} debug={result.debug} />;

  const application = result.application;
  if (!can(appPermissions, "app_firewall", "view", "application")) {
    return <PermissionDenied title={t("pageTitle")} />;
  }
  const canManage = can(appPermissions, "app_firewall", "manage", "application");
  const settled = isSettled(application);

  // Category and mode labels come from the API, so their failure is a load
  // failure. The web server lookup is non-fatal.
  const [{ categories, modes, failed: optionsFailed, status: optionsStatus, failure: optionsFailure, message: optionsMessage }, { webServer }] = settled
    ? await Promise.all([getWafOptions(), getServerCapabilities()])
    : [{ categories: [], modes: [], failed: false }, { webServer: null }];

  // Only watching (detect) mode writes this log, and the API lists it only then.
  const watching = settled && application.waf_enabled && application.waf_mode === "detect";
  const detect = watching
    ? await getApplicationLog(id, "waf_detect", { lines: 200 })
    : null;
  // 'missing' (404) is normal until the first match, so it is not a failure.
  const detectFailed = detect?.status === "failed" || detect?.status === "locked";
  const detectRows = detect?.log?.lines?.length ? parseDetectLog(detect.log.lines) : [];

  /*
   * Prefer the API's per-application `waf_supported`. The web server name check
   * is a fallback for APIs that predate the field.
   */
  const unsupported =
    typeof application.waf_supported === "boolean"
      ? !application.waf_supported
      : webServer === "openlitespeed";

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
      ) : optionsFailed || categories.length === 0 || modes.length === 0 ? (
        <LoadFailed description={t("loadFailed")} status={optionsStatus} failure={optionsFailure} message={optionsMessage} />
      ) : (
        <>
          {/* Settings still save where unsupported, but nothing enforces them. */}
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
            detectCount={detectRows.length}
            detectFailed={detectFailed}
          />
          {watching ? (
            <>
              {/* Keeps the match list current; unsaved form edits survive the refresh. */}
              <AutoRefresh intervalMs={30000} stopAfterMs={600000} />
              <DetectLogCard rows={detectRows} failed={detectFailed} />
            </>
          ) : null}
        </>
      )}
    </div>
  );
}
