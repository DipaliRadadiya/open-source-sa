import { getTranslations } from "next-intl/server";
import { ShieldOff } from "lucide-react";
import { EmptyState } from "@/components/data-table/empty-state";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getServerFacts } from "@/lib/server/get-server-facts";
import { getServerHistory } from "@/lib/server/get-server-history";
import { historySeries } from "@/lib/server/history-series";
import { getServerProcesses } from "@/lib/server/get-server-processes";
import { getServiceHealth } from "@/lib/server/get-service-health";
import { getSetup } from "@/lib/setup/get-setup";
import { getAllApplications } from "@/lib/applications/get-applications";
import { getEngines } from "@/lib/databases/get-databases";
import { attentionFindings } from "@/lib/dashboard/attention";
import { SetupBanner } from "@/components/setup/setup-banner";
import { ApplicationEmptyState } from "@/components/applications/application-empty-state";
import { LiveMetricsSection } from "@/components/dashboard/live-metrics-section";
import { ServerInfoCard } from "@/components/dashboard/server-info-card";
import { ProcessesCard } from "@/components/dashboard/processes-card";
import { PageHeader } from "@/components/ui/page-header";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("serverDashboard");
  return { title: t("title") };
}

export default async function DashboardPage() {
  const [permissions, t, tDenied] = await Promise.all([
    getPermissions(),
    getTranslations("serverDashboard"),
    getTranslations("common.permissionDenied"),
  ]);

  // Only someone who could act on setup gets the nudge, and only while the
  // recommended set is incomplete.
  const setupResult = can(permissions, "setting", "view") ? await getSetup() : { setup: null };
  const setupRemaining = setupResult.setup && !setupResult.setup.complete
    ? setupResult.setup.components.filter((c) => c.recommended && c.state !== "installed").length
    : 0;

  // Without `view` the page stays empty rather than redirecting: it is the
  // landing route, so a redirect would loop.
  const allowed = can(permissions, "dashboard", "view");
  const canManage = can(permissions, "dashboard", "manage");
  // Needed to lead with the "create an application" invitation on an empty server.
  const canViewApplications = can(permissions, "application", "view");
  // Engines from `/databases/engines`, not `/server/facts`: facts misreport
  // MariaDB and miss MongoDB and PostgreSQL.
  const canViewDatabases = can(permissions, "database", "view");
  // History is the last 24 hours at five-minute samples, so fetched once per
  // render rather than polled.
  const [facts, processResult, health, history, appResult, engineResult] = allowed
    ? await Promise.all([
        getServerFacts(),
        getServerProcesses(),
        // Null when services cannot be read; the card then shows no verdict.
        getServiceHealth(),
        getServerHistory(),
        // The whole list (up to the API maximum of 100), not one page, so
        // problems beyond the first page are still found.
        canViewApplications ? getAllApplications() : Promise.resolve(null),
        // Without database `view` the engine chips are simply omitted.
        canViewDatabases ? getEngines() : Promise.resolve({ engines: [] }),
      ])
    : [null, { data: [], failed: false }, null, [], null, { engines: [] }];

  // A failed read must never become "no sites" or "everything is fine".
  const known = Boolean(appResult && !appResult.failed);
  const applications = known ? appResult.applications : [];
  const firstRun = known && applications.length === 0;
  const attention = attentionFindings(applications);

  return (
    <div className="space-y-6">
      <PageHeader title={t("title")} subtitle={t("subtitle")} />

      <SetupBanner remaining={setupRemaining} />

      {allowed ? (
        <>
          {/* Compact, so the live numbers stay above the fold. */}
          {firstRun ? (
            <ApplicationEmptyState
              canManage={can(permissions, "application", "manage")}
              compact
            />
          ) : null}
          <ServerInfoCard
            facts={facts}
            health={health}
            siteAttention={attention}
            engines={engineResult?.engines ?? []}
            /* A failed read, distinct from "no databases". */
            enginesFailed={Boolean(engineResult?.failed)}
          />
          <LiveMetricsSection
            timeZone={facts?.timezone}
            history={historySeries(history)}
          />
          <ProcessesCard
            data={processResult.data}
            failed={processResult.failed}
            /* Server-wide count, not rows returned. Null on APIs without `meta.total`. */
            total={processResult.total}
            canManage={canManage}
          />
        </>
      ) : (
        /* Same copy as <PermissionDenied />, which cannot be used here because
           this landing route renders its own header first. */
        <EmptyState
          icon={ShieldOff}
          title={tDenied("title", { feature: t("title") })}
          description={tDenied("description")}
        />
      )}
    </div>
  );
}
