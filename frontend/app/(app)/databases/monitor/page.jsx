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
import { TAB_LINK_GROUP, tabLinkClass } from "@/lib/theme/tab-link";
import { HealthSummary } from "@/components/databases/health-summary";
import { EngineStatusCards } from "@/components/databases/engine-status-cards";
import { QueryChart } from "@/components/databases/query-chart";
import { ProcessList } from "@/components/databases/process-list";
import { EmptyState } from "@/components/data-table/empty-state";
import { LoadFailed } from "@/components/data-table/load-failed";
import { Activity, Database } from "lucide-react";
import { cn } from "@/lib/utils";
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

      <div className="space-y-4">
        {/* Only with a real choice; with one engine its name is in the title. Only
            running engines are listed, so each carries the green running dot. */}
        {running.length > 1 ? (
          <div className={TAB_LINK_GROUP}>
            {running.map((engine) => (
              <Link
                key={engine.engine}
                href={`/databases/monitor?engine=${engine.engine}`}
                aria-current={engine === selected ? "page" : undefined}
                className={cn(tabLinkClass(engine === selected), "gap-2")}
              >
                <Database className="size-4 text-muted-foreground" aria-hidden />
                {tEngines(engine.engine)}
                <span aria-hidden className="size-1.5 rounded-full bg-success" />
              </Link>
            ))}
          </div>
        ) : null}

        <HealthSummary engine={selected} status={status} processes={processes} />

        <EngineStatusCards status={status} processes={processes} />

        {/* The day's chart beside what is running now; stacked below xl, queries first,
            since running queries are what the reader came to see. */}
        {/* The chart sets the row's height; the queries card fills it and scrolls
            inside (absolute, so a long list cannot make the row taller). */}
        <div className="grid gap-4 xl:grid-cols-3">
          <div className="xl:relative xl:order-2">
            <div className="xl:absolute xl:inset-0">
              <ProcessList
                engine={selected.engine}
                processes={processes}
                connections={connections}
                canManage={canManage}
                fill
              />
            </div>
          </div>
          <div className="min-w-0 xl:order-1 xl:col-span-2">
            <QueryChart metrics={metrics} timeZone={facts?.timezone} />
          </div>
        </div>
      </div>
    </div>
  );
}

/** Plain title; engine, version and uptime live in the health summary below. The back
    link was asked for (1 Oct) even with the breadcrumb, so it stays. */
function Header({ t }) {
  return (
    <div className="space-y-3">
      <BackLink href="/databases">{t("backToList")}</BackLink>
      <PageHeader title={t("title")} subtitle={t("subtitle")} />
    </div>
  );
}
