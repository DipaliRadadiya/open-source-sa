import { getTranslations } from "next-intl/server";
import { ShieldOff } from "lucide-react";
import { EmptyState } from "@/components/data-table/empty-state";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { getCurrentUser } from "@/lib/auth/get-current-user";
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
import { GettingStarted } from "@/components/dashboard/getting-started";
import { LiveMetricsSection } from "@/components/dashboard/live-metrics-section";
import { ServerInfoCard } from "@/components/dashboard/server-info-card";
import { AttentionPanel } from "@/components/dashboard/attention-panel";
import { DashboardHero } from "@/components/dashboard/dashboard-hero";
import { ProcessesCard } from "@/components/dashboard/processes-card";
import { PageHeader } from "@/components/ui/page-header";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("serverDashboard");
  return { title: t("title") };
}

export default async function DashboardPage() {
  const [permissions, t, tDenied, user] = await Promise.all([
    getPermissions(),
    getTranslations("serverDashboard"),
    getTranslations("common.permissionDenied"),
    // Cached: the layout already asked.
    getCurrentUser().catch(() => null),
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
  const canViewServices = can(permissions, "service", "view");
  // With neither answer there is nothing the panel could vouch for.
  const showAttention = canViewApplications || Boolean(health);

  return (
    <div className="space-y-6">
      {/* The banner is the page heading; without `view` there is nothing for it to report. */}
      {allowed ? (
        <DashboardHero
          userName={user?.name ?? null}
          facts={facts}
          health={health}
          findings={attention}
          applications={known ? applications : null}
          canCreate={can(permissions, "application", "manage")}
          canViewLogs={can(permissions, "logs", "view")}
        />
      ) : (
        <PageHeader title={t("title")} subtitle={t("subtitle")} />
      )}

      <SetupBanner remaining={setupRemaining} />

      {allowed ? (
        <>
          {/* Only on an empty server, and only on a list we actually received. */}
          {firstRun ? (
            <GettingStarted
              canCreate={can(permissions, "application", "manage")}
              ip={facts?.public_ip ?? facts?.ip}
            />
          ) : null}
          {/* First after the banner: what needs doing outranks how busy the server is.
              Renders nothing when all is well; the banner already says so. */}
          {showAttention ? (
            <AttentionPanel
              findings={attention}
              health={health}
              applicationsKnown={known || !canViewApplications}
              canViewServices={canViewServices}
            />
          ) : null}
          <LiveMetricsSection
            timeZone={facts?.timezone}
            history={historySeries(history)}
            between={
              <ServerInfoCard
                facts={facts}
                health={health}
                engines={engineResult?.engines ?? []}
                /* A failed read, distinct from "no databases". */
                enginesFailed={Boolean(engineResult?.failed)}
                canViewServices={canViewServices}
              />
            }
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
