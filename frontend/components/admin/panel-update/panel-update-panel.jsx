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
// Past this, say "taking longer" but keep polling; the final status is rebuilt
// from the runner's state file regardless.
const SLOW_AFTER_MS = 8 * 60 * 1000;

const isActive = (run) => Boolean(run) && (run.status === "pending" || run.status === "running");

export function PanelUpdatePanel({ initialState, title, subtitle }) {
  const t = useTranslations("panelUpdate");
  const router = useRouter();

  const [state, setState] = useState(initialState);
  // Seed with the latest run even if settled: the update copy says it's safe to
  // leave, so the page is usually loaded after a run finished.
  const [run, setRun] = useState(initialState.latest_run ?? null);
  const [dryRun, setDryRun] = useState(false);
  const [checking, setChecking] = useState(false);
  const [starting, setStarting] = useState(false);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [reconnecting, setReconnecting] = useState(false);
  const [slow, setSlow] = useState(false);
  // A terminal success may already have triggered a reload. Keep it out of
  // the first paint until sessionStorage can say whether it is new or stale.
  const [successReady, setSuccessReady] = useState(
    initialState.latest_run?.status !== "succeeded",
  );

  const activeRunId = isActive(run) ? run.id : null;
  // A failed run from another build (the panel was updated or redeployed since) says
  // nothing about this one: on 5 Oct a 29 Sep "didn't finish" sat above "Up to date".
  const staleRun =
    Boolean(run) &&
    !isActive(run) &&
    run.status !== "succeeded" &&
    Boolean(run.from_commit) &&
    Boolean(state.installed?.commit_hash) &&
    run.from_commit !== state.installed.commit_hash;
  const visibleRun = staleRun || (run?.status === "succeeded" && !successReady) ? null : run;

  // A successful run remains latest_run after reloading into the new code; hide
  // the run already reloaded for, or it starts a fresh countdown forever.
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

  // Poll only while a run is active. The panel restarts mid-update, so errors only flag "reconnecting".
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
      // A failed reply does not prove the update failed (lost on restart, or schema mismatch
      // on a valid 202); ask the source of truth.
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
        // Possibly the restart window. Keep the original error unless the probe proves
        // a run exists.
      }

      if (!recovered) toast.error(apiMessage(error, t("startFailed")));
    } finally {
      setStarting(false);
    }
  }

  // The run's own flag too: after a reload the local `dryRun` is gone.
  const runIsDry = dryRun || Boolean(run?.dry_run);

  function onFinish() {
    // Reload only when the code changed: a dry run touches nothing, and a failed real
    // run was rolled back by the script's ERR trap.
    if (!runIsDry && run?.status === "succeeded") {
      acknowledgePanelUpdate(window.sessionStorage, run.id);
      window.location.reload();
      return;
    }

    setRun(null);
    setDryRun(false);
    router.refresh();
  }

  // Shown beside the button as well as in its label, so a disabled Update needs
  // no hover to explain.
  const blockedReason = !state.preflight.ready ? t("notReady") : null;

  // On the status card, not in the page header: actions sit on the card they act on.
  const checkButton = (
    <Button variant="outline" onClick={checkAgain} disabled={checking}>
      <RefreshCw className={checking ? "size-4 animate-spin" : "size-4"} />
      {t("checkAgain")}
    </Button>
  );

  // Both actions plus the disabled reason, as one block closing the header row.
  const updateActions = (
    // A column sized by its wider row, which keeps the reason on one line under the
    // buttons.
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
      {/* Capped so the long dry-run hint wraps; every locale's blocked reason fits. */}
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
              divided={state.update_available}
              actions={state.update_available ? updateActions : <div className="sm:ml-auto">{checkButton}</div>}
            />

            {state.update_available ? (
              <>
                <CardContent className="px-6 py-5">
                  <PreflightList checks={state.preflight.checks} />
                </CardContent>
                <ReleaseNotes notes={state.available.notes} url={state.available.url} />
              </>
            ) : null}
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
