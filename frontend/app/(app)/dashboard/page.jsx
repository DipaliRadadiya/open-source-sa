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
import { getApplications } from "@/lib/applications/get-applications";
import { SetupBanner } from "@/components/setup/setup-banner";
import { ApplicationEmptyState } from "@/components/applications/application-empty-state";
import { LiveMetricsSection } from "@/components/dashboard/live-metrics-section";
import { ServerInfoCard } from "@/components/dashboard/server-info-card";
import { ProcessesCard } from "@/components/dashboard/processes-card";

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
  // The landing route is the one screen a first-time user is guaranteed to
  // see, and until now it was the same monitoring page whether the server had
  // fifty sites or none. Asking how many there are is what lets it lead with
  // the thing they came for instead of an idle machine's vital signs.
  const canViewApplications = can(permissions, "application", "view");
  // Load and resource usage are the last 24 hours, from the five-minute
  // `server:sample-metrics` collector. Fetched here, once per render — polling
  // a table that gains a row every five minutes would be pointless.
  const [facts, processResult, health, history, appResult] = allowed
    ? await Promise.all([
        getServerFacts(),
        getServerProcesses(),
        // Null when the user cannot read services — the card then says nothing
        // rather than claiming everything is fine.
        getServiceHealth(),
        getServerHistory(),
        // Not fetched for a reader who could never be offered the card anyway.
        canViewApplications ? getApplications("") : Promise.resolve(null),
      ])
    : [null, { data: [], failed: false }, null, [], null];

  // Only on a total we actually got. A failed list read means "could not ask",
  // and "you have no sites" is a claim worth making only when it is true —
  // announcing an empty server on the strength of a failed request would greet
  // someone with fifty sites by inviting them to create their first.
  const firstRun = Boolean(appResult && !appResult.failed && appResult.meta.total === 0);

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
      </div>

      <SetupBanner remaining={setupRemaining} />

      {allowed ? (
        <>
          {/* Above the server's own content on a server that has none of the
              user's yet: the invitation is the only thing on this page they can
              act on, and the compact layout keeps the live numbers in view
              rather than pushing them past the fold. */}
          {firstRun ? (
            <ApplicationEmptyState
              canManage={can(permissions, "application", "manage")}
              compact
            />
          ) : null}
          {/* Identity first — "which machine am I on" is read once, on
              arrival — then the live numbers, then four even charts: the last
              day for load and usage, then the live throughput pair. */}
          <ServerInfoCard facts={facts} health={health} />
          <LiveMetricsSection
            timeZone={facts?.timezone}
            history={historySeries(history)}
          />
          <ProcessesCard
            data={processResult.data}
            failed={processResult.failed}
            /* How many the server is running, not how many rows came back.
               Null on an API that predates `meta.total`. */
            total={processResult.total}
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
