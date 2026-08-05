"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations, useFormatter } from "next-intl";
import { toast } from "sonner";
import { ArrowUpCircle, ArrowRight, CircleCheck, RefreshCw, TriangleAlert, FlaskConical, ExternalLink, WifiOff } from "lucide-react";
import { startPanelUpdate, fetchPanelUpdateRun, refreshPanelUpdateState } from "@/lib/api/panel-update";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { VersionCard } from "./version-card";
import { PreflightList } from "./preflight-list";
import { UpdateProgress } from "./update-progress";

const POLL_MS = 2500;
// Updates take a few minutes; past this we say "taking longer" but keep polling
// — the final status is reconstructed from the runner's state file regardless.
const SLOW_AFTER_MS = 8 * 60 * 1000;

const isActive = (run) => Boolean(run) && (run.status === "pending" || run.status === "running");

export function PanelUpdatePanel({ initialState }) {
  const t = useTranslations("panelUpdate");
  const format = useFormatter();
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

  const activeRunId = isActive(run) ? run.id : null;

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
      toast.error(apiMessage(error, t("startFailed")));
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
      window.location.reload();
      return;
    }

    setRun(null);
    setDryRun(false);
    router.refresh();
  }

  const publishedLabel = (() => {
    if (!state.available.published_at) return null;
    const d = new Date(state.available.published_at);
    return Number.isNaN(d.getTime()) ? null : format.dateTime(d, { dateStyle: "medium" });
  })();

  return (
    <div className="space-y-6">
      <VersionCard installed={state.installed} />

      {isActive(run) ? (
        <UpdateProgress run={run} reconnecting={reconnecting} slow={slow} dryRun={dryRun} onFinish={onFinish} />
      ) : (
        <>
          {run ? <UpdateProgress run={run} dryRun={dryRun} onFinish={onFinish} /> : null}

          {state.update_available ? (
            <div className="space-y-4 rounded-2xl border border-primary/30 bg-primary/[0.03] p-5">
              <div className="flex items-start gap-4">
                <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary/10">
                  <ArrowUpCircle className="size-6 text-primary" aria-hidden />
                </span>
                <div className="min-w-0 flex-1 space-y-1">
                  <p className="font-medium">{t("updateAvailable", { version: state.available.version })}</p>
                  {state.installed.version && state.available.version ? (
                    <p className="flex items-center gap-1.5 font-mono text-sm text-muted-foreground">
                      v{state.installed.version}
                      <ArrowRight className="size-3.5" aria-hidden />
                      <span className="font-medium text-foreground">v{state.available.version}</span>
                    </p>
                  ) : null}
                  {publishedLabel ? (
                    <p className="text-sm text-muted-foreground">{t("published", { date: publishedLabel })}</p>
                  ) : null}
                  {state.available.notes ? (
                    <pre className="mt-2 max-h-48 overflow-auto whitespace-pre-wrap rounded-lg border bg-muted/40 p-3 font-sans text-sm leading-6">
                      {state.available.notes}
                    </pre>
                  ) : null}
                  {state.available.url ? (
                    <a
                      href={state.available.url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
                    >
                      {t("releaseNotes")}
                      <ExternalLink className="size-3.5" />
                    </a>
                  ) : null}
                </div>
              </div>

              <PreflightList checks={state.preflight.checks} />
              {!state.preflight.ready ? (
                <p className="flex items-start gap-2 text-sm text-warning">
                  <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                  <span>{t("notReady")}</span>
                </p>
              ) : null}

              <div className="flex flex-wrap gap-2">
                <Button onClick={() => setConfirmOpen(true)} disabled={!state.preflight.ready || starting}>
                  <ArrowUpCircle className="size-4" />
                  {t("updateNow")}
                </Button>
                <Button variant="outline" onClick={() => begin(true)} disabled={starting}>
                  <FlaskConical className="size-4" />
                  {t("dryRun")}
                </Button>
                <Button variant="ghost" onClick={checkAgain} disabled={checking}>
                  <RefreshCw className={checking ? "size-4 animate-spin" : "size-4"} />
                  {t("checkAgain")}
                </Button>
              </div>

              {/* "Dry run" is jargon — say what it does so it isn't scary. */}
              <p className="text-xs text-muted-foreground">{t("dryRunHint")}</p>
            </div>
          ) : state.available.checked ? (
            <div className="flex flex-wrap items-center gap-3 rounded-2xl border border-success/30 bg-success/5 p-4">
              <CircleCheck className="size-5 shrink-0 text-success" />
              <p className="flex-1 text-sm font-medium">{t("upToDate")}</p>
              <Button variant="outline" size="sm" onClick={checkAgain} disabled={checking}>
                <RefreshCw className={checking ? "size-4 animate-spin" : "size-4"} />
                {t("checkAgain")}
              </Button>
            </div>
          ) : (
            <div className="flex flex-wrap items-center gap-3 rounded-2xl border bg-muted/30 p-4">
              <WifiOff className="size-5 shrink-0 text-muted-foreground" />
              <div className="flex-1">
                <p className="text-sm font-medium">{t("couldNotCheck")}</p>
                <p className="text-sm text-muted-foreground">{t("couldNotCheckBody")}</p>
              </div>
              <Button variant="outline" size="sm" onClick={checkAgain} disabled={checking}>
                <RefreshCw className={checking ? "size-4 animate-spin" : "size-4"} />
                {t("checkAgain")}
              </Button>
            </div>
          )}
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
