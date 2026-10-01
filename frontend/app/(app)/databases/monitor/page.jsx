import Link from "@/components/ui/app-link";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getConnections, getEngines } from "@/lib/databases/get-databases";
import {
  getEngineStatus,
  getDatabaseMetrics,
  getProcesses,
} from "@/lib/databases/get-monitor";
import { getServerFacts } from "@/lib/server/get-server-facts";
import { Button } from "@/components/ui/button";
import { HealthSummary } from "@/components/databases/health-summary";
import { EngineStatusCards } from "@/components/databases/engine-status-cards";
import { QueryChart } from "@/components/databases/query-chart";
import { ProcessList } from "@/components/databases/process-list";
import { EmptyState } from "@/components/data-table/empty-state";
import { LoadFailed } from "@/components/data-table/load-failed";
import { Activity } from "lucide-react";
import { PageCrumb } from "@/components/sections/page-crumb";
import { PageHeader } from "@/components/ui/page-header";
import { BackLink } from "@/components/ui/back-link";
import { PermissionDenied } from "@/components/sections/permission-denied";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("databases.monitor");
  return { title: t("title") };
}

export default async function DatabaseMonitorPage({ searchParams }) {
  const sp = await searchParams;
  const [permissions, t, tEngines, live] = await Promise.all([
    getPermissions(),
    getTranslations("databases.monitor"),
    getTranslations("databases.engines"),
    getEngines(),
  ]);
  const { engines, failed: enginesFailed, status: enginesStatus, failure: enginesFailure, message: enginesMessage } = live;

  if (!can(permissions, "database", "view")) return <PermissionDenied title={t("title")} />;
  const canManage = can(permissions, "database", "manage");

  // Only a running engine has data; `?engine=` picks when more than one runs.
  const running = engines.filter((engine) => engine.running);
  const selected =
    running.find((engine) => engine.engine === sp?.engine) ?? running[0] ?? null;

  if (!selected) {
    return (
      <div className="space-y-6">
        <PageCrumb>{t("crumb")}</PageCrumb>
        <Header t={t} />
        {/* A failed request is not evidence that no engine is running. */}
        {enginesFailed ? (
          <LoadFailed description={t("loadFailed")} status={enginesStatus} failure={enginesFailure} message={enginesMessage} />
        ) : (
          <EmptyState
            icon={Activity}
            title={t("noEngine.title")}
            description={t("noEngine.description")}
          />
        )}
      </div>
    );
  }

  const [status, metrics, processes, facts, connections] = await Promise.all([
    getEngineStatus(selected.engine),
    getDatabaseMetrics(selected.engine),
    getProcesses(selected.engine),
    getServerFacts(),
    // Only used to recognise the panel's own connection; must not fail the page.
    getConnections().catch(() => []),
  ]);

  return (
    <div className="space-y-6">
      <PageCrumb>{t("crumb")}</PageCrumb>
      <Header t={t} engine={selected} />

      <div className="max-w-5xl space-y-4">
        {/* Only with a real choice; with one engine its name is in the title. */}
        {running.length > 1 ? (
          <div className="flex items-center gap-2">
            {running.map((engine) => (
              <Button
                key={engine.engine}
                asChild
                size="sm"
                variant={engine === selected ? "default" : "outline"}
              >
                <Link
                  href={`/databases/monitor?engine=${engine.engine}`}
                  aria-current={engine === selected ? "page" : undefined}
                >
                  {tEngines(engine.engine)}
                </Link>
              </Button>
            ))}
          </div>
        ) : null}

        <HealthSummary engine={selected} status={status} processes={processes} />

        <EngineStatusCards status={status} processes={processes} />

        {/* Queries before the chart: running queries are what the reader came to see. */}
        <ProcessList
          engine={selected.engine}
          processes={processes}
          connections={connections}
          canManage={canManage}
        />
        <QueryChart metrics={metrics} timeZone={facts?.timezone} />
      </div>
    </div>
  );
}

/** Plain title; engine, version and uptime live in the health summary below. */
function Header({ t }) {
  return (
    <div className="space-y-3">
      <BackLink href="/databases">{t("backToList")}</BackLink>
      <PageHeader title={t("title")} subtitle={t("subtitle")} />
    </div>
  );
}
