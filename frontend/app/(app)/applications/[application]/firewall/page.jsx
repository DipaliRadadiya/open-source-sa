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
import { getApplicationLog } from "@/lib/applications/get-application-logs";
import { parseDetectLog } from "@/lib/firewall/parse-detect-log";
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
  // The site is gone. Land on the list — the only place left to go — and say
  // why on arrival, rather than parking on a dead end that offers one link.
  if (result.status === 404) redirect("/applications?gone=1");
  if (result.failed || !result.application) return <LoadFailed description={t("loadFailed")} status={result.status} failure={result.failure} />;

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

  // Watching mode is the only mode that writes this, and the API only lists
  // the key while it is on — so this read is conditional on the same thing.
  const watching = settled && application.waf_enabled && application.waf_mode === "detect";
  const detect = watching
    ? await getApplicationLog(id, "waf_detect", { lines: 200 })
    : null;
  // 'missing' is a 404 for the file, which is the NORMAL state until the first
  // match — it must read as "nothing caught yet", never as a failure. Only a
  // read error or a permission refusal is a failure.
  const detectFailed = detect?.status === "failed" || detect?.status === "locked";
  const detectRows = detect?.log?.lines?.length ? parseDetectLog(detect.log.lines) : [];

  const unsupported = webServer === "openlitespeed";

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
            detectCount={detectRows.length}
          />
          {watching ? (
            <DetectLogCard rows={detectRows} failed={detectFailed} />
          ) : null}
        </>
      )}
    </div>
  );
}
