"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { ArrowUpCircle, RefreshCw, TriangleAlert, FlaskConical } from "lucide-react";
import {
  startPanelUpdate,
  fetchPanelUpdateRun,
  fetchPanelUpdateState,
  refreshPanelUpdateState,
} from "@/lib/api/panel-update";
import { apiMessage } from "@/lib/api/error-message";
import { shouldRecoverPanelUpdate } from "@/lib/admin/recover-panel-update";
import {
  acknowledgePanelUpdate,
  isPanelUpdateAcknowledged,
} from "@/lib/admin/panel-update-acknowledgement";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { PageHeader } from "@/components/ui/page-header";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { ActionIcon } from "@/components/ui/action-icon";
import { UpdateHeader } from "./update-header";
import { ReleaseNotes } from "./release-notes";
import { PreflightList } from "./preflight-list";
import { UpdateProgress } from "./update-progress";

const POLL_MS = 2500;
const SLOW_AFTER_MS = 8 * 60 * 1000;

const isActive = (run) => Boolean(run) && (run.status === "pending" || run.status === "running");

export function PanelUpdatePanel({ initialState, title, subtitle }) {
  const t = useTranslations("panelUpdate");
  const router = useRouter();

  const [state, setState] = useState(initialState);
  const [run, setRun] = useState(initialState.latest_run ?? null);
  const [dryRun, setDryRun] = useState(false);
  const [checking, setChecking] = useState(false);
  const [starting, setStarting] = useState(false);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [reconnecting, setReconnecting] = useState(false);
  const [slow, setSlow] = useState(false);
  // A finished update may already have reloaded; wait for sessionStorage before showing it.
  const [successReady, setSuccessReady] = useState(
    initialState.latest_run?.status !== "succeeded",
  );

  const activeRunId = isActive(run) ? run.id : null;
  const staleRun =
    Boolean(run) &&
    !isActive(run) &&
    run.status !== "succeeded" &&
    Boolean(run.from_commit) &&
    Boolean(state.installed?.commit_hash) &&
    run.from_commit !== state.installed.commit_hash;
  const visibleRun = staleRun || (run?.status === "succeeded" && !successReady) ? null : run;

  useEffect(() => {
    if (run?.status !== "succeeded") return undefined;

    let live = true;
    const acknowledged = isPanelUpdateAcknowledged(window.sessionStorage, run.id);

    queueMicrotask(() => {
      if (!live) return;
      if (acknowledged) setRun(null);
      setSuccessReady(true);
    });

    return () => {
      live = false;
    };
  }, [run?.id, run?.status]);

  useEffect(() => {
    if (!activeRunId) return undefined;
    const startedAt = Date.now();
    let live = true;
    const timer = setInterval(async () => {
      if (Date.now() - startedAt > SLOW_AFTER_MS) setSlow(true);
      try {
        const next = await fetchPanelUpdateRun(activeRunId);
        if (live) {
          setReconnecting(false);
          setRun(next);
        }
      } catch {
        if (live) setReconnecting(true);
      }
    }, POLL_MS);
    return () => {
      live = false;
      clearInterval(timer);
    };
  }, [activeRunId]);

  async function checkAgain() {
    setChecking(true);
    try {
      const next = await refreshPanelUpdateState();
      setState(next);
      if (isActive(next.latest_run)) setRun(next.latest_run);
    } catch (error) {
      toast.error(apiMessage(error, t("checkFailed")));
    } finally {
      setChecking(false);
    }
  }

  async function begin(asDryRun) {
    setStarting(true);
    try {
      const started = await startPanelUpdate({ dryRun: asDryRun });
      setDryRun(asDryRun);
      setSlow(false);
      setReconnecting(false);
      setRun(started);
      setConfirmOpen(false);
    } catch (error) {
      let recovered = false;

      try {
        const next = await fetchPanelUpdateState();
        const latest = next.latest_run;
        setState(next);

        if (shouldRecoverPanelUpdate(latest, state.latest_run?.id)) {
          setDryRun(asDryRun);
          setSlow(false);
          setReconnecting(false);
          setRun(latest);
          setConfirmOpen(false);
          recovered = true;
        }
      } catch {
        // keep the original error unless the probe finds a run
      }

      if (!recovered) toast.error(apiMessage(error, t("startFailed")));
    } finally {
      setStarting(false);
    }
  }

  const runIsDry = dryRun || Boolean(run?.dry_run);

  function onFinish() {
    if (!runIsDry && run?.status === "succeeded") {
      acknowledgePanelUpdate(window.sessionStorage, run.id);
      window.location.reload();
      return;
    }

    setRun(null);
    setDryRun(false);
    router.refresh();
  }

  const blockedReason = !state.preflight.ready ? t("notReady") : null;

  const checkButton = (
    <Button variant="outline" onClick={checkAgain} disabled={checking}>
      <RefreshCw className={checking ? "size-4 animate-spin" : "size-4"} />
      {t("checkAgain")}
    </Button>
  );

  const updateActions = (
    <div className="flex w-full flex-col items-end gap-1.5 sm:ml-auto sm:w-auto">
      <div className="flex flex-wrap items-center justify-end gap-2">
        {checkButton}
        <Button variant="outline" onClick={() => begin(true)} disabled={starting}>
          <ActionIcon icon={FlaskConical} pending={starting} className="size-4" />
          {t("dryRun")}
        </Button>
        <ReasonTooltip reason={blockedReason}>
          <Button onClick={() => setConfirmOpen(true)} disabled={Boolean(blockedReason) || starting}>
            <ArrowUpCircle className="size-4" />
            {t("updateNow")}
          </Button>
        </ReasonTooltip>
      </div>
      <p className="max-w-md text-xs text-muted-foreground sm:text-right">
        {blockedReason ?? t("dryRunHint")}
      </p>
    </div>
  );

  return (
    <div className="space-y-6">
      <PageHeader title={title} subtitle={subtitle} />

      {isActive(run) ? (
        <UpdateProgress run={run} reconnecting={reconnecting} slow={slow} dryRun={runIsDry} onFinish={onFinish} />
      ) : (
        <>
          {visibleRun ? (
            <UpdateProgress run={visibleRun} dryRun={dryRun || Boolean(visibleRun.dry_run)} onFinish={onFinish} />
          ) : null}

          <Card className="gap-0 overflow-hidden py-0">
            <UpdateHeader
              state={state}
              divided
              actions={state.update_available ? updateActions : <div className="sm:ml-auto">{checkButton}</div>}
            />

            {state.update_available ? (
              <>
                <CardContent className="px-6 py-5">
                  <PreflightList checks={state.preflight.checks} />
                </CardContent>
                <ReleaseNotes notes={state.available.notes} url={state.available.url} />
              </>
            ) : (
              <CardContent className="px-6 py-5">
                <PreflightList checks={state.preflight.checks} title={t("preflightNext")} />
              </CardContent>
            )}
          </Card>
        </>
      )}

      <ConfirmDialog
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        icon={TriangleAlert}
        tone="warning"
        title={t("confirmTitle")}
        description={t("confirmBody")}
        cancelLabel={t("cancel")}
        confirmLabel={t("updateNow")}
        pending={starting}
        onConfirm={() => begin(false)}
      />
    </div>
  );
}
