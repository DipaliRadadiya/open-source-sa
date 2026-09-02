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
// Updates take a few minutes; past this we say "taking longer" but keep polling
// — the final status is reconstructed from the runner's state file regardless.
const SLOW_AFTER_MS = 8 * 60 * 1000;

const isActive = (run) => Boolean(run) && (run.status === "pending" || run.status === "running");

/**
 * Owns the whole screen, heading included, because "Check again" belongs beside
 * the title (as Re-check does on System health) and it drives the same state
 * the card below reads.
 */
export function PanelUpdatePanel({ initialState, title, subtitle }) {
  const t = useTranslations("panelUpdate");
  const router = useRouter();

  const [state, setState] = useState(initialState);
  // Seed with the latest run regardless of whether it is still active: a real
  // update takes minutes and its own copy says it's safe to leave, so the
  // common case is loading this page well after a run has already settled.
  // Without this, a finished run is invisible — no success card, no failure
  // card — until the tab that watched it live is the one still open.
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
  const visibleRun = run?.status === "succeeded" && !successReady ? null : run;

  // A successful run remains the backend's latest_run after the browser has
  // reloaded into the new code. Remember the run we reloaded for and hide that
  // one on the next mount, otherwise it starts a fresh countdown forever.
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

  // Poll only while a run is active. Same id + still active → no re-subscribe;
  // terminal → the effect re-runs and tears the interval down. Mid-update the
  // panel restarts (503 / refused) — that's normal, so errors just flag
  // "reconnecting" and polling continues.
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
      // A failed reply does not prove the update failed to start. The request
      // can create the run and then lose its response while the panel begins
      // restarting; a client schema mismatch can also reject an otherwise
      // valid 202. Ask the source of truth before telling the user nothing
      // happened. If a run exists, the progress screen is the answer.
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
        // The panel may be in the brief service-restart window. Preserve the
        // original error below; the recovery probe must never hide it unless
        // it can prove a run exists.
      }

      if (!recovered) toast.error(apiMessage(error, t("startFailed")));
    } finally {
      setStarting(false);
    }
  }

  function onFinish() {
    // A hard reload is only warranted when the code actually changed under
    // this client. A dry run never touches anything, and a failed real run
    // has already been rolled back by the script's own ERR trap — in both
    // cases the running code is exactly what it was before, so reloading
    // would just replay the same page pointlessly.
    if (!dryRun && run?.status === "succeeded") {
      acknowledgePanelUpdate(window.sessionStorage, run.id);
      window.location.reload();
      return;
    }

    setRun(null);
    setDryRun(false);
    router.refresh();
  }

  // What is standing in the way of the primary button, or null once it is live.
  // Printed beside the button as well as read out on it: on a screen you visit
  // once a month, "why is Update grey" should not need a hover to answer.
  const blockedReason = !state.preflight.ready ? t("notReady") : null;

  // Both actions plus the reason the primary one is off, as one block that the
  // header band closes its row with. Printed as well as read out on hover: on a
  // screen you visit once a month, "why is Update grey" should not need a hover.
  const updateActions = (
    // A column, sized by whichever of its two rows is wider. That is what keeps
    // the reason on one line: given a fixed width it wrapped, and given the
    // whole row it sat beside the buttons instead of under them.
    <div className="flex w-full flex-col items-end gap-1.5 sm:ml-auto sm:w-auto">
      <div className="flex flex-wrap items-center justify-end gap-2">
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
      {/* Capped so the long dry-run hint wraps rather than pushing the version
          off its own row; every locale's blocked reason fits inside it. */}
      <p className="max-w-md text-xs text-muted-foreground sm:text-right">
        {blockedReason ?? t("dryRunHint")}
      </p>
    </div>
  );

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <PageHeader title={title} subtitle={subtitle} />
        {/* Not shrink-0: "Check again" is a verb phrase and grows in other
            locales, so it wraps under the heading rather than overflowing. */}
        <Button variant="outline" onClick={checkAgain} disabled={checking}>
          <RefreshCw className={checking ? "size-4 animate-spin" : "size-4"} />
          {t("checkAgain")}
        </Button>
      </div>

      {isActive(run) ? (
        <UpdateProgress run={run} reconnecting={reconnecting} slow={slow} dryRun={dryRun} onFinish={onFinish} />
      ) : (
        <>
          {visibleRun ? (
            <UpdateProgress run={visibleRun} dryRun={dryRun} onFinish={onFinish} />
          ) : null}

          <Card className="gap-0 overflow-hidden py-0 shadow-sm">
            <UpdateHeader
              state={state}
              divided={state.update_available}
              actions={state.update_available ? updateActions : null}
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
