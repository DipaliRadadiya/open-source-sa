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
        // Not fetched for a reader who could be shown neither the card nor the
        // health chip.
        //
        // The whole list, not a page of it: `getApplications("")` stops at ten,
        // which answers "are there none" but would scan only the first page for
        // problems — a broken site on page two would never be mentioned. Capped
        // at the API's own maximum of 100, so a server past that loses the tail
        // here; the honest fix at that point is a server-wide issues endpoint
        // rather than a bigger number.
        canViewApplications ? getAllApplications() : Promise.resolve(null),
      ])
    : [null, { data: [], failed: false }, null, [], null];

  // Both of these are claims about the server, and a failed read is not
  // evidence for either. "You have no sites" told to somebody with fifty, or
  // "everything is fine" told over an unanswered request, are the two ways this
  // could lie, and `failed` is what stops both.
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
          <ServerInfoCard facts={facts} health={health} siteAttention={attention} />
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
