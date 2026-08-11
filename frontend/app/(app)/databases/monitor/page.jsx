import { redirect } from "next/navigation";
import Link from "next/link";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getEngines } from "@/lib/databases/get-databases";
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
import { Activity } from "lucide-react";
import { PageCrumb } from "@/components/sections/page-crumb";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("databases.monitor");
  return { title: t("title") };
}

export default async function DatabaseMonitorPage({ searchParams }) {
  const sp = await searchParams;
  const [permissions, t, live] = await Promise.all([
    getPermissions(),
    getTranslations("databases.monitor"),
    getEngines(),
  ]);
  const { engines } = live;

  if (!can(permissions, "database", "view")) redirect("/dashboard");
  const canManage = can(permissions, "database", "manage");

  // Only a reachable engine has anything to report. With two running, the
  // `?engine=` param picks; otherwise there is nothing to choose.
  const running = engines.filter((engine) => engine.running);
  const selected =
    running.find((engine) => engine.engine === sp?.engine) ?? running[0] ?? null;

  if (!selected) {
    return (
      <div className="space-y-6">
        <PageCrumb>{t("crumb")}</PageCrumb>
        <Header t={t} />
        <EmptyState
          icon={Activity}
          title={t("noEngine.title")}
          description={t("noEngine.description")}
        />
      </div>
    );
  }

  const [status, metrics, processes, facts] = await Promise.all([
    getEngineStatus(selected.engine),
    getDatabaseMetrics(selected.engine),
    getProcesses(selected.engine),
    getServerFacts(),
  ]);

  return (
    <div className="space-y-6">
      <PageCrumb>{t("crumb")}</PageCrumb>
      <Header t={t} engine={selected} />

      <div className="max-w-5xl space-y-4">
        {/* Only when there is a choice to make. A switch with one option reads
            as a step you have to take — with one engine its identity rides in
            the page title instead. */}
        {running.length > 1 ? (
          <div className="flex items-center gap-2">
            {running.map((engine) => (
              <Button
                key={engine.engine}
                asChild
                size="sm"
                variant={engine === selected ? "default" : "outline"}
              >
                <Link href={`/databases/monitor?engine=${engine.engine}`}>
                  {engine.engine}
                </Link>
              </Button>
            ))}
          </div>
        ) : null}

        <HealthSummary engine={selected} status={status} processes={processes} />

        <EngineStatusCards status={status} processes={processes} />

        {/* Queries before the chart. Someone opens this page because something
            is slow *now*; the 24h history is what you consult after seeing
            what is running. With the chart first, the query rows — including
            the one that has been going for two minutes — sat below the fold. */}
        <ProcessList
          engine={selected.engine}
          processes={processes}
          canManage={canManage}
        />
        <QueryChart metrics={metrics} timeZone={facts?.timezone} />
      </div>
    </div>
  );
}

/**
 * Plain title. The engine, its version, whether it is up and for how long all
 * live in the health summary below — repeating them here would be the same
 * fact twice, a few pixels apart.
 */
function Header({ t }) {
  return (
    <div className="space-y-1">
      <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
      <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
    </div>
  );
}
