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
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
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
      <Header t={t} />

      <div className="max-w-5xl space-y-4">
        {/* Only when there is a choice to make. A switch with one option reads
            as a step you have to take. */}
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
        ) : (
          <div className="flex items-center gap-2 text-sm">
            <span className="font-medium">{selected.engine}</span>
            {selected.version ? (
              <span className="font-mono text-xs text-muted-foreground">
                {selected.version}
              </span>
            ) : null}
            <Badge variant="success" className="font-normal">
              {t("running")}
            </Badge>
          </div>
        )}

        <EngineStatusCards status={status} />
        <QueryChart metrics={metrics} timeZone={facts?.timezone} />
        <ProcessList
          engine={selected.engine}
          processes={processes}
          canManage={canManage}
        />
      </div>
    </div>
  );
}

function Header({ t }) {
  return (
    <div className="space-y-3">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
      </div>
    </div>
  );
}
