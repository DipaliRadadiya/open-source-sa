import { Suspense } from "react";
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
import { getAllApplications } from "@/lib/applications/get-applications";
import { getEngines } from "@/lib/databases/get-databases";
import { attentionFindings } from "@/lib/dashboard/attention";
import { DashboardSetupNotes } from "@/components/dashboard/setup-notes";
import { getSetup } from "@/lib/setup/get-setup";
import { getDashboardOverview } from "@/lib/dashboard/get-overview";
import { GettingStarted } from "@/components/dashboard/getting-started";
import { LiveMetricsSection } from "@/components/dashboard/live-metrics-section";
import { ServerInfoCard } from "@/components/dashboard/server-info-card";
import { AttentionPanel } from "@/components/dashboard/attention-panel";
import { DashboardHero } from "@/components/dashboard/dashboard-hero";
import { DashboardOverviewSection, DashboardOverviewSkeleton } from "@/components/dashboard/dashboard-overview-section";
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

  const canViewSetup = can(permissions, "setting", "view");

  // No redirect without view permission: this is the landing route, so it would loop.
  const allowed = can(permissions, "dashboard", "view");
  const canManage = can(permissions, "dashboard", "manage");
  const canViewApplications = can(permissions, "application", "view");
  const canViewDatabases = can(permissions, "database", "view");
  const overviewRead = allowed
    ? getDashboardOverview({
        databases: canViewDatabases,
        cronjobs: can(permissions, "cronjob", "view"),
        systemUsers: can(permissions, "system_user", "view"),
        firewall: can(permissions, "firewall", "view"),
      })
    : null;
  const setupRead = canViewSetup ? getSetup() : null;
  const [facts, processResult, health, history, appResult, engineResult] = allowed
    ? await Promise.all([
        getServerFacts(),
        getServerProcesses(),
        getServiceHealth(),
        getServerHistory(),
        canViewApplications ? getAllApplications() : Promise.resolve(null),
        canViewDatabases ? getEngines() : Promise.resolve({ engines: [] }),
      ])
    : [null, { data: [], failed: false }, null, [], null, { engines: [] }];

  const known = Boolean(appResult && !appResult.failed);
  const applications = known ? appResult.applications : [];
  const firstRun = known && applications.length === 0;
  const appCatalog = await getPermissions("application").catch(() => null);
  const attention = attentionFindings(applications, appCatalog);
  const canViewServices = can(permissions, "service", "view");
  const showAttention = canViewApplications || Boolean(health);

  return (
    <div className="space-y-6">
      {allowed ? (
        <DashboardHero
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

      {allowed ? (
        <Suspense fallback={<DashboardOverviewSkeleton />}>
          <DashboardOverviewSection
            applications={canViewApplications ? (known ? applications : null) : undefined}
            read={overviewRead}
            canManageFirewall={can(permissions, "firewall", "manage")}
          />
        </Suspense>
      ) : null}

      {setupRead ? (
        <Suspense fallback={null}>
          <DashboardSetupNotes read={setupRead} />
        </Suspense>
      ) : null}

      {allowed ? (
        <>
          {firstRun ? (
            <GettingStarted
              canCreate={can(permissions, "application", "manage")}
              ip={facts?.public_ip ?? facts?.ip}
            />
          ) : null}
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
                enginesFailed={Boolean(engineResult?.failed)}
                canViewServices={canViewServices}
              />
            }
          />
          <ProcessesCard
            data={processResult.data}
            failed={processResult.failed}
            total={processResult.total}
            canManage={canManage}
          />
        </>
      ) : (
        <EmptyState
          icon={ShieldOff}
          title={tDenied("title", { feature: t("title") })}
          description={tDenied("description")}
        />
      )}
    </div>
  );
}
