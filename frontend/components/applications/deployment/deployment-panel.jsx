"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { useRouter, useSearchParams } from "next/navigation";
import { toast } from "sonner";
import { History, Settings2, Webhook } from "lucide-react";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { ScrollFade } from "@/components/ui/scroll-fade";
import { deployApplication } from "@/lib/api/applications";
import { fetchLatestDeployment, readApplication } from "@/lib/api/deployment";
import { latestDeploymentResponseSchema } from "@/lib/schemas/deploy-history";
import { isNewPush, latestChanged, watchDelay, WATCH_MS } from "@/lib/applications/latest-deploy";
import { applicationSchema } from "@/lib/schemas/application";
import { apiMessage } from "@/lib/api/error-message";
import { provisionStepLabel } from "@/lib/applications/provision-steps";
import { DeployCard } from "@/components/applications/deployment/deploy-card";
import { WebhookCard } from "@/components/applications/deployment/webhook-card";
import { DeploySettingsCard } from "@/components/applications/deployment/deploy-settings-card";
import { RuntimeCard } from "@/components/applications/deployment/runtime-card";
import { DeployHistoryCard } from "@/components/applications/deployment/deploy-history-card";
import { LoadFailed } from "@/components/data-table/load-failed";

// A deploy flips status to "provisioning" while it runs; poll the resource so
// steps[] and the commit/timestamp update in place without leaving the page.
const POLL_MS = 2500;
const TABS = ["history", "settings", "automation"];
// Longer than any deploy the backend allows to run. Past it the poll stops
// costing requests and the page re-reads instead of spinning on.
const DEPLOY_WATCH_LIMIT_MS = 30 * 60 * 1000;

// Matching components/applications/domains/domains-ssl-tabs.jsx exactly:
// !h-auto overrides shadcn TabsList's hard-coded height so the py padding lands.
const TRIGGER = "!h-auto gap-2 px-4 py-2";

export function DeploymentPanel({
  application: initial,
  providers,
  canManage,
  canViewLogs = false,
  deployments = [],
  settings = null,
  history = null,
  gitAccounts = [],
}) {
  const t = useTranslations("applications.deployment");
  // Step labels live under `details` — the namespace of the first screen that
  // needed them — and a raw `verify` in a toast is as unreadable as in a card.
  const ts = useTranslations("applications.details");
  const router = useRouter();
  const [application, setApplication] = useState(initial);
  const [deploying, setDeploying] = useState(false);
  const searchParams = useSearchParams();
  const [tab, setTabState] = useState(() => (TABS.includes(searchParams.get("tab")) ? searchParams.get("tab") : "history"));
  // In the address, like Domains & SSL, so a reload or a shared link lands on
  // the same tab. replaceState, not router: a tab is not a navigation.
  const setTab = useCallback((next) => {
    setTabState(next);
    const params = new URLSearchParams(window.location.search);
    params.set("tab", next);
    window.history.replaceState(null, "", `?${params.toString()}`);
  }, []);
  const pollRef = useRef(null);
  // The failure banner and the build log sit in two different cards; this is
  // the one place that can see both.
  const historyRef = useRef(null);

  // Only the newest deploy's log answers "why did THIS fail". An older failed
  // run further down the list is a different question, and `failed_step` can
  // also come from provisioning, where there is no deploy to open at all.
  const lastFailed = deployments[0]?.status === "failed" ? deployments[0] : null;

  const stopPoll = useCallback(() => {
    if (pollRef.current) {
      clearInterval(pollRef.current);
      pollRef.current = null;
    }
  }, []);

  useEffect(() => () => stopPoll(), [stopPoll]);

  /*
   * Switch tab and open the log in one go.
   *
   * No waiting for the tab to become active first: every TabsContent here is
   * `forceMount`, so the history card is mounted from the start and its ref is
   * live whichever tab is showing. An earlier version parked the deployment in
   * state and opened it from an effect once the tab changed, which was both a
   * `set-state-in-effect` lint error and a dance around a problem that
   * `forceMount` had already solved.
   */
  const showBuildLog = useCallback((deployment) => {
    setTab("history");
    historyRef.current?.show(deployment);
  }, [setTab]);

  const refresh = useCallback(async () => {
    try {
      const { data } = await readApplication(application.id);
      const parsed = applicationSchema.safeParse(data?.application);
      if (parsed.success) {
        setApplication(parsed.data);
        return parsed.data;
      }
    } catch {
      // Transient poll error — keep the last good state and try again.
    }
    return null;
  }, [application.id]);

  // The verdict on a finished deploy, whichever way it was started.
  const announce = useCallback(
    (next, before) => {
      if (next.code_on_disk?.state === "incomplete") {
        // Failed after the checkout: the new commit is live, half-built.
        toast.error(next.code_on_disk.message || t("deploy.incomplete"));
      } else if (next.failed_step) {
        toast.error(t("deploy.failedAt", { step: provisionStepLabel(next.failed_step, ts) }));
      } else if (next.last_deployed_at !== before) {
        toast.success(t("deploy.done"));
      }
    },
    [t, ts],
  );

  const deploy = useCallback(async () => {
    const before = application.last_deployed_at;
    const startedAt = Date.now();
    setDeploying(true);
    try {
      await deployApplication(application.id);
      toast.info(t("deploy.started"));
      stopPoll();
      pollRef.current = setInterval(async () => {
        if (Date.now() - startedAt > DEPLOY_WATCH_LIMIT_MS) {
          stopPoll();
          setDeploying(false);
          router.refresh();
          return;
        }
        const next = await refresh();
        if (!next) return;
        // Back to a settled state — a failed redeploy leaves the site "active"
        // with failed_step set, so read that, not the status, for the verdict.
        if (next.status === "active" || next.status === "failed") {
          stopPoll();
          setDeploying(false);
          // The history is a server-component prop, and polling only ever
          // re-read the application — so the run that just finished never
          // appeared, and its commit never appeared, until someone reloaded
          // the page by hand. Re-run the server component instead.
          router.refresh();
          announce(next, before);
        }
      }, POLL_MS);
    } catch (error) {
      setDeploying(false);
      toast.error(apiMessage(error, t("deploy.failed")));
    }
  }, [application.id, application.last_deployed_at, refresh, stopPoll, router, t, announce]);

  /*
   * Watch for deploys this page did not start: a push, or someone else.
   *
   * The history is a server render, so a deploy started by a webhook stayed
   * invisible until a reload. Every 5 s (2.5 s while one runs) the page asks
   * for the newest deploy and re-reads only when it differs from the top row.
   * Paused while the tab is hidden and asked again on return: a background tab
   * polling a shared rate limit helps nobody.
   *
   * The top row and `deploying` are read through refs so a history that just
   * re-rendered does not restart the timer.
   */
  const topRef = useRef(deployments[0] ?? null);
  // The deploy being watched to its end, kept apart from `topRef`: the history
  // re-render that follows a change can land after the deploy finished, and
  // reading "was it running?" off the refreshed row then missed the ending.
  const watchingRef = useRef(deployments[0]?.in_flight ? deployments[0].id : null);
  const deployingRef = useRef(deploying);
  useEffect(() => {
    topRef.current = deployments[0] ?? null;
  }, [deployments]);
  useEffect(() => {
    deployingRef.current = deploying;
  }, [deploying]);

  useEffect(() => {
    let timer = null;
    let stopped = false;
    let delay = WATCH_MS;

    async function tick() {
      timer = null;
      if (document.hidden) return;
      try {
        const { data } = await fetchLatestDeployment(application.id);
        const parsed = latestDeploymentResponseSchema.safeParse(data);
        if (!stopped && parsed.success) {
          const latest = parsed.data.latest;
          const top = topRef.current;
          delay = watchDelay(latest, deployingRef.current);
          // A deploy this page did not start (a push, a redeploy from the
          // list, another tab) just ended: say how, as a deploy started here
          // would. This page's own deploy is announced by its own poll.
          // Decided before and apart from `latestChanged`, whose comparison
          // row a history refresh may already have moved past the ending.
          if (latest?.in_flight && !deployingRef.current) watchingRef.current = latest.id;
          const finished = Boolean(latest) && latest.id === watchingRef.current && !latest.in_flight;
          if (finished) watchingRef.current = null;
          if (finished || latestChanged(latest, top)) {
            if (isNewPush(latest, top) && !deployingRef.current) {
              toast.info(t("history.pushStarted", { branch: latest.branch ?? application.branch ?? "main" }));
            }
            // Held until the refreshed history replaces it, so the same change
            // is not acted on twice while the server render is on its way.
            topRef.current = latest;
            // The Deploy card reads the application (commit, failed step), the
            // history reads the server render: both have moved.
            const next = await refresh();
            router.refresh();
            if (finished && next) announce(next);
          }
        }
      } catch {
        // A missed check is not worth a toast; the next one will tell.
      }
      if (!stopped && !document.hidden) timer = setTimeout(tick, delay);
    }

    function onVisibility() {
      if (document.hidden) {
        clearTimeout(timer);
        timer = null;
      } else if (!timer && !stopped) {
        tick();
      }
    }

    timer = setTimeout(tick, delay);
    document.addEventListener("visibilitychange", onVisibility);
    return () => {
      stopped = true;
      clearTimeout(timer);
      document.removeEventListener("visibilitychange", onVisibility);
    };
  }, [application.id, application.branch, refresh, router, t, announce]);

  /*
   * A deploy this page did not start.
   *
   * Auto-deploy fires from a push, and the panel only ever polled after its own
   * button was pressed — so an open page sat on a stale history while a deploy
   * ran and finished behind it. Provisioning is the same signal either way, so
   * watching for it covers both without polling a page where nothing is
   * happening.
   */
  useEffect(() => {
    if (deploying || application.status !== "provisioning" || pollRef.current) return undefined;

    pollRef.current = setInterval(async () => {
      const next = await refresh();
      if (!next || next.status === "provisioning") return;
      stopPoll();
      router.refresh();
    }, POLL_MS);

    return stopPoll;
  }, [deploying, application.status, refresh, stopPoll, router]);

  return (
    <div className="space-y-6">
      {/* The hero, outside the tabs: what is deployed and the button that
          changes it are the two things that must be true on every tab. Five
          cards of identical weight is what made this page read as a wall, and
          the flattest part was that the thing the page exists for had the same
          weight as the port number. */}
      <DeployCard
        application={application}
        deploying={deploying || application.status === "provisioning" || Boolean(deployments[0]?.in_flight)}
        gitAccounts={gitAccounts}
        canManage={canManage}
        canViewLogs={canViewLogs}
        onDeploy={deploy}
        onShowBuildLog={lastFailed ? () => showBuildLog(lastFailed) : null}
      />

      <Tabs value={tab} onValueChange={setTab} className="gap-4">
        <ScrollFade>
          <TabsList className="!h-auto w-fit gap-1 p-1">
            <TabsTrigger value="history" className={TRIGGER}>
              <History className="size-4" />
              {t("tabs.history")}
            </TabsTrigger>
            <TabsTrigger value="settings" className={TRIGGER}>
              <Settings2 className="size-4" />
              {t("tabs.settings")}
            </TabsTrigger>
            <TabsTrigger value="automation" className={TRIGGER}>
              <Webhook className="size-4" />
              {t("tabs.automation")}
            </TabsTrigger>
          </TabsList>
        </ScrollFade>

        {/* History first: it is what you want the second after pressing Deploy,
            and it used to be a thousand pixels below the button. */}
        <TabsContent value="history" forceMount className="data-[state=inactive]:hidden">
          {/* An unanswered read is not an empty history: "No deploys yet" on
              a site with fifty was the page asserting something it never
              learned. */}
          {history?.failed ? (
            <LoadFailed description={t("history.loadFailed")} status={history.status} failure={history.failure} message={history.message} debug={history.debug} />
          ) : (
            <DeployHistoryCard
              applicationId={application.id}
              deployments={deployments}
              canManage={canManage}
              ref={historyRef}
            />
          )}
        </TabsContent>

        <TabsContent
          value="settings"
          forceMount
          className="space-y-6 data-[state=inactive]:hidden"
        >
          {!settings && history?.failed ? (
            <LoadFailed description={t("history.loadFailed")} status={history.status} failure={history.failure} message={history.message} debug={history.debug} />
          ) : null}
          {settings ? (
            <DeploySettingsCard
              applicationId={application.id}
              application={application}
              settings={settings}
              canManage={canManage}
            />
          ) : null}
          {/* Only a site that runs a process has one to start, and the fields
              are meaningless on a static or PHP site — the API nulls them. */}
          {application.has_process ? (
            <RuntimeCard application={application} canManage={canManage} />
          ) : null}
        </TabsContent>

        <TabsContent value="automation" forceMount className="data-[state=inactive]:hidden">
          <WebhookCard
            application={application}
            providers={providers}
            canManage={canManage}
            onChange={setApplication}
          />
        </TabsContent>
      </Tabs>
    </div>
  );
}
