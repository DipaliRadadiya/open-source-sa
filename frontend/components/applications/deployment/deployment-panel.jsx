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

// Poll while a deploy runs ("provisioning") so steps and commit update in place.
const POLL_MS = 2500;
const TABS = ["history", "settings", "automation"];
// Longer than any deploy the backend allows; past it the poll stops and the page re-reads.
const DEPLOY_WATCH_LIMIT_MS = 30 * 60 * 1000;

// `!h-auto` overrides TabsList's fixed height (as in domains-ssl-tabs.jsx).
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
  // Step labels live under `details`; raw step ids are unreadable in a toast.
  const ts = useTranslations("applications.details");
  const router = useRouter();
  const [application, setApplication] = useState(initial);
  // In state so a poll can update it; a new server render still wins.
  const [renderedFrom, setRenderedFrom] = useState(initial);
  if (initial !== renderedFrom) {
    setRenderedFrom(initial);
    setApplication(initial);
  }
  const [deploying, setDeploying] = useState(false);
  const searchParams = useSearchParams();
  const [tab, setTabState] = useState(() => (TABS.includes(searchParams.get("tab")) ? searchParams.get("tab") : "history"));
  // In the URL so a reload keeps the tab; replaceState because a tab change is not a navigation.
  const setTab = useCallback((next) => {
    setTabState(next);
    const params = new URLSearchParams(window.location.search);
    params.set("tab", next);
    window.history.replaceState(null, "", `?${params.toString()}`);
  }, []);
  const pollRef = useRef(null);
  // Set when a poll gives up, so the provisioning watch does not take over and poll forever.
  const gaveUpRef = useRef(false);
  // The failure banner and build log are in different cards; this sees both.
  const historyRef = useRef(null);

  // Only the newest deploy's log explains this failure; `failed_step` can also
  // come from provisioning, with no deploy to open.
  const lastFailed = deployments[0]?.status === "failed" ? deployments[0] : null;

  const stopPoll = useCallback(() => {
    if (pollRef.current) {
      clearInterval(pollRef.current);
      pollRef.current = null;
    }
  }, []);

  useEffect(() => () => stopPoll(), [stopPoll]);

  // Every TabsContent is `forceMount`, so the history ref is live on any tab.
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
      // Transient poll error: keep the last good state and retry.
    }
    return null;
  }, [application.id]);

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
    gaveUpRef.current = false;
    setDeploying(true);
    try {
      await deployApplication(application.id);
      toast.info(t("deploy.started"));
      stopPoll();
      pollRef.current = setInterval(async () => {
        if (Date.now() - startedAt > DEPLOY_WATCH_LIMIT_MS) {
          gaveUpRef.current = true;
          stopPoll();
          setDeploying(false);
          router.refresh();
          return;
        }
        const next = await refresh();
        if (!next) return;
        // A failed redeploy leaves the site "active" with failed_step set; read that, not the status.
        if (next.status === "active" || next.status === "failed") {
          stopPoll();
          setDeploying(false);
          // The history is a server-component prop; re-run it so the finished run appears.
          router.refresh();
          announce(next, before);
        }
      }, POLL_MS);
    } catch (error) {
      setDeploying(false);
      toast.error(apiMessage(error, t("deploy.failed")));
    }
  }, [application.id, application.last_deployed_at, refresh, stopPoll, router, t, announce]);

  // Watch for deploys this page did not start: re-read the history when the newest deploy differs.
  // Paused while hidden (shared rate limit); refs keep a history re-render from restarting the timer.
  const topRef = useRef(deployments[0] ?? null);
  // Apart from `topRef`: a refreshed row may already be past the end, so "was it running?" misses it.
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
          // A deploy this page did not start just ended (its own are announced by its poll).
          // Decided before `latestChanged`, whose row a refresh may already have moved past the end.
          if (latest?.in_flight && !deployingRef.current) watchingRef.current = latest.id;
          const finished = Boolean(latest) && latest.id === watchingRef.current && !latest.in_flight;
          if (finished) watchingRef.current = null;
          if (finished || latestChanged(latest, top)) {
            if (isNewPush(latest, top) && !deployingRef.current) {
              toast.info(t("history.pushStarted", { branch: latest.branch ?? application.branch ?? "main" }));
            }
            // Held until the refreshed history replaces it, so one change is not handled twice.
            topRef.current = latest;
            // Both the Deploy card (application) and the history (server render) have changed.
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

  // Auto-deploy from a push: watch provisioning so an open page does not show a stale history.
  useEffect(() => {
    if (deploying || application.status !== "provisioning" || pollRef.current || gaveUpRef.current) return undefined;

    const startedAt = Date.now();
    pollRef.current = setInterval(async () => {
      if (Date.now() - startedAt > DEPLOY_WATCH_LIMIT_MS) {
        gaveUpRef.current = true;
        stopPoll();
        router.refresh();
        return;
      }
      const next = await refresh();
      if (!next || next.status === "provisioning") return;
      stopPoll();
      router.refresh();
    }, POLL_MS);

    return stopPoll;
  }, [deploying, application.status, refresh, stopPoll, router]);

  return (
    <div className="space-y-6">
      {/* Outside the tabs: the deployed state and its button must be visible on every tab. */}
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

        {/* History first: it is what is wanted right after pressing Deploy. */}
        <TabsContent value="history" forceMount className="data-[state=inactive]:hidden">
          {/* A failed read is not an empty history. */}
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
          {/* Only process-running sites have these fields; the API nulls them otherwise. */}
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
