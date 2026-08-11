import { getTranslations } from "next-intl/server";
import { ShieldOff } from "lucide-react";
import { EmptyState } from "@/components/data-table/empty-state";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getServerFacts } from "@/lib/server/get-server-facts";
import { getServerProcesses } from "@/lib/server/get-server-processes";
import { getServiceHealth } from "@/lib/server/get-service-health";
import { getSetup } from "@/lib/setup/get-setup";
import { SetupBanner } from "@/components/setup/setup-banner";
import { LiveMetricsSection } from "@/components/server-dashboard/live-metrics-section";
import { ServerInfoCard } from "@/components/server-dashboard/server-info-card";
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

  // Only someone who could act on setup gets the nudge, and only while the
  // recommended set is incomplete.
  const setupResult = can(permissions, "setting", "view") ? await getSetup() : { setup: null };
  const setupRemaining = setupResult.setup && !setupResult.setup.complete
    ? setupResult.setup.components.filter((c) => c.recommended && c.state !== "installed").length
    : 0;

  // Dashboard is permission-gated; without `view` the page stays empty rather
  // than redirecting (it's the panel's landing route — a redirect would loop).
  const allowed = can(permissions, "dashboard", "view");
  // Stopping a process is a write, so it needs `manage`, not `view`.
  const canManage = can(permissions, "dashboard", "manage");
  // No history fetch: /server/metrics/history returns nothing on any box we
  // can reach, and every chart now reads the live poll instead. The fetcher
  // stays in lib/server for when the collector ships and the charts gain a
  // Live/24h switch.
  const [facts, processResult, health] = allowed
    ? await Promise.all([
        getServerFacts(),
        getServerProcesses(),
        // Null when the user cannot read services — the card then says nothing
        // rather than claiming everything is fine.
        getServiceHealth(),
      ])
    : [null, { data: [], failed: false }, null];

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
      </div>

      <SetupBanner remaining={setupRemaining} />

      {allowed ? (
        <>
          {/* Identity first — "which machine am I on" is read once, on
              arrival — then the live numbers, then four even history charts:
              the network pair, then the system pair. */}
          <ServerInfoCard facts={facts} health={health} />
          <LiveMetricsSection timeZone={facts?.timezone} />
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
