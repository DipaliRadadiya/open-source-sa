import { getTranslations } from "next-intl/server";
import { ShieldOff } from "lucide-react";
import { EmptyState } from "@/components/data-table/empty-state";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getServerFacts } from "@/lib/server/get-server-facts";
import { getServerHistory } from "@/lib/server/get-server-history";
import { getServerProcesses } from "@/lib/server/get-server-processes";
import { getServiceHealth } from "@/lib/server/get-service-health";
import { LiveMetricsSection } from "@/components/server-dashboard/live-metrics-section";
import { ServerInfoCard } from "@/components/server-dashboard/server-info-card";
import { MetricsCharts } from "@/components/server-dashboard/metrics-charts";
import { ProcessesCard } from "@/components/server-dashboard/processes-card";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("serverDashboard");
  return { title: t("title") };
}

export default async function DashboardPage() {
  const [permissions, t] = await Promise.all([
    getPermissions(),
    getTranslations("serverDashboard"),
  ]);

  // Dashboard is permission-gated; without `view` the page stays empty rather
  // than redirecting (it's the panel's landing route — a redirect would loop).
  const allowed = can(permissions, "dashboard", "view");
  // Stopping a process is a write, so it needs `manage`, not `view`.
  const canManage = can(permissions, "dashboard", "manage");
  const [facts, history, processResult, health] = allowed
    ? await Promise.all([
        getServerFacts(),
        getServerHistory(),
        getServerProcesses(),
        // Null when the user cannot read services — the card then says nothing
        // rather than claiming everything is fine.
        getServiceHealth(),
      ])
    : [null, [], { data: [], failed: false }, null];

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
      </div>

      {allowed ? (
        <>
          {/* Network chart pairs with the server facts on the same row. */}
          <LiveMetricsSection timeZone={facts?.timezone}>
            <ServerInfoCard facts={facts} health={health} />
          </LiveMetricsSection>
          <MetricsCharts history={history} timeZone={facts?.timezone} />
          <ProcessesCard
            data={processResult.data}
            failed={processResult.failed}
            canManage={canManage}
          />
        </>
      ) : (
        <EmptyState
          icon={ShieldOff}
          title={t("noPermission.title")}
          description={t("noPermission.description")}
        />
      )}
    </div>
  );
}
