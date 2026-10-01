import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { CircleAlert, CircleCheck, EyeOff, Loader2, RefreshCw, TriangleAlert, Undo2, X } from "lucide-react";
import { cn } from "@/lib/utils";
import { RESTORE_IN_FLIGHT } from "@/lib/schemas/backup";
import { reasonText } from "@/lib/backups/reason";
import { fetchBackup, fetchRestore } from "@/lib/api/backups";
import { apiMessage } from "@/lib/api/error-message";
import { useRefresh } from "@/hooks/use-refresh";
import { Button } from "@/components/ui/button";
import { Progress } from "@/components/ui/progress";
import { RestoreDialog } from "@/components/backups/restore-dialog";

/** The backend polls comfortably at 120/min; every 2s is well inside that. */
const POLL_MS = 2000;

/** Give up after 20 minutes; a restore that has not moved by then is stuck. */
const POLL_LIMIT_MS = 20 * 60 * 1000;

/**
 * A restore that has not STARTED is waiting for a worker, which takes seconds,
 * so it gets a much shorter limit.
 */
const QUEUED_LIMIT_MS = 2 * 60 * 1000;

/*
 * Banner buttons: the action (Undo, Check again) is the default filled button;
 * closing (Dismiss, Hide) is neutral white, never the message's status colour.
 */
const NEUTRAL =
  "border-transparent bg-background text-foreground shadow-xs hover:bg-muted dark:bg-secondary dark:hover:bg-muted";

/**
 * A restore, while it happens and after it finishes. The steps come from the
 * API (`step_number` / `total_steps`), so the bar reflects real progress.
 */
export function RestoreProgress({
  restore: initial,
  applicationDomain,
  // True when the run on screen restored a safety copy, i.e. it was an undo.
  // Supplied by the page so a reload mid-undo says so too.
  restoredSafetyCopy = false,
  // Told each status this banner learns, so the page can block the actions
  // that must wait for a restore.
  onStatusChange,
  onDismiss,
}) {
  const t = useTranslations("backups.progress");
  const { refreshAndWait } = useRefresh();
  const router = useRouter();
  const [restore, setRestore] = useState(initial);
  const [undoBackup, setUndoBackup] = useState(null);
  // Whether this run put the safety copy back. An undo takes its own safety
  // copy, so without this the banner would offer to "undo" the undo, which
  // reinstalls the first restore. Seeded from the prop for reloads mid-undo.
  const [wasUndo, setWasUndo] = useState(Boolean(restoredSafetyCopy));
  const [loadingUndo, setLoadingUndo] = useState(false);
  // Set when polling gives up: the restore is still `pending`/`running` as far
  // as the API is concerned, but nothing has moved for a long time.
  const [stalled, setStalled] = useState(false);
  // Bumped by "Check again" so polling restarts even when the status it finds
  // is the same one it gave up on.
  const [round, setRound] = useState(0);
  const [checking, setChecking] = useState(false);
  const timer = useRef(null);
  // Through a ref: callers pass an inline function, and as an effect
  // dependency it restarted the polling (and its give-up timer) every render.
  const statusRef = useRef(onStatusChange);
  useEffect(() => {
    statusRef.current = onStatusChange;
  });

  const inFlight = RESTORE_IN_FLIGHT.includes(restore?.status);

  // Queued: `pending` with no `started_at` means the site has not been touched yet.
  const queued = restore?.status === "pending" && !restore?.started_at;

  const id = restore?.id;

  useEffect(() => {
    if (!inFlight || !id) return undefined;

    async function poll() {
      try {
        const response = await fetchRestore(id);
        const next = response.data?.restore;
        if (!next) return;
        setRestore(next);
        statusRef.current?.(next.status, next.id);
        // The site's files and database changed; refresh every other screen.
        if (!RESTORE_IN_FLIGHT.includes(next.status)) router.refresh();
      } catch {
        // Transient errors are ignored; the next tick retries.
      }
    }

    timer.current = setInterval(poll, POLL_MS);
    // Stop polling eventually: a restore no worker picks up stays `pending` forever.
    const stop = setTimeout(() => {
      clearInterval(timer.current);
      // Say so, rather than leave a spinner implying work is happening.
      setStalled(true);
    }, queued ? QUEUED_LIMIT_MS : POLL_LIMIT_MS);

    return () => {
      clearInterval(timer.current);
      clearTimeout(stop);
    };
  }, [inFlight, id, queued, router, round]);

  // Ask about THIS restore: the banner keeps its own copy, so a page refresh
  // would not update it.
  async function checkAgain() {
    setChecking(true);
    try {
      const response = await fetchRestore(id);
      const next = response.data?.restore;
      if (next) {
        setRestore(next);
        statusRef.current?.(next.status, next.id);
        if (!RESTORE_IN_FLIGHT.includes(next.status)) await refreshAndWait();
      }
      setStalled(false);
      setRound((current) => current + 1);
    } catch (error) {
      toast.error(apiMessage(error, t("checkFailed")));
    } finally {
      setChecking(false);
    }
  }

  if (!restore) return null;

  const total = restore.total_steps ?? 0;
  const step = restore.step_number ?? 0;
  const percent = total > 0 ? Math.round((step / total) * 100) : 0;

  async function openUndo() {
    setLoadingUndo(true);
    try {
      const response = await fetchBackup(restore.safety_backup_id);
      const backup = response.data?.backup;
      if (!backup) throw new Error("missing");
      // Put back only what this restore replaced (e.g. database only).
      setUndoBackup({ ...backup, application_domain: applicationDomain, preferred_type: restore.type });
    } catch (error) {
      toast.error(apiMessage(error, t("undoFailed")));
    } finally {
      setLoadingUndo(false);
    }
  }

  if (restore.status === "succeeded") {
    return (
      <>
        <div className="flex flex-col items-start gap-3 rounded-2xl border border-success/30 bg-success/5 p-5">
          <div className="flex items-start gap-3">
            <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-success/10">
              <CircleCheck className="size-6 text-success" aria-hidden />
            </span>
            <div className="space-y-1">
              <p className="font-medium">{wasUndo ? t("undone") : t("succeeded")}</p>
              <p className="text-sm text-muted-foreground">
                {wasUndo
                  ? t("undoneBody")
                  : restore.finished_at_human
                    ? t("succeededBody", { when: restore.finished_at_human })
                    : t("succeededBodyPlain")}
              </p>
            </div>
          </div>

          {/* Undo and Dismiss share one size and style. */}
          <div className="ml-14 flex flex-wrap gap-2">
            {restore.safety_backup_id && !wasUndo ? (
              <Button size="sm" onClick={openUndo} disabled={loadingUndo}>
                {loadingUndo ? (
                  <Loader2 className="size-4 animate-spin" />
                ) : (
                  <Undo2 className="size-4" />
                )}
                {t("undo")}
              </Button>
            ) : null}
            <Button variant="secondary" size="sm" onClick={onDismiss} className={NEUTRAL}>
              <X className="size-4" />
              {t("dismiss")}
            </Button>
          </div>

          {restore.safety_backup_id && !wasUndo ? (
            <p className="ml-14 text-xs text-muted-foreground">{t("undoHint")}</p>
          ) : null}
        </div>

        <RestoreDialog
          key={undoBackup?.id}
          backup={undoBackup}
          open={Boolean(undoBackup)}
          onOpenChange={(next) => (next ? null : setUndoBackup(null))}
          onStarted={(next) => {
            setUndoBackup(null);
            if (!next) return;
            // The run that follows IS the undo, so its banner must not offer undo.
            setWasUndo(true);
            setRestore(next);
            onStatusChange?.(next.status, next.id);
          }}
        />
      </>
    );
  }

  if (restore.status === "failed") {
    return (
      <div className="flex flex-col items-start gap-3 rounded-2xl border border-destructive/30 bg-destructive/5 p-5">
        <div className="flex items-start gap-3">
          <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-destructive/10">
            <CircleAlert className="size-6 text-destructive" aria-hidden />
          </span>
          <div className="min-w-0 space-y-1">
            <p className="font-medium">{t("failed")}</p>
            {/* A translated key naming the failed step, never raw stderr (the
                backend does not send it). */}
            <p className="text-sm text-muted-foreground">
              {reasonText(restore.reason_title, t("unknownReason"))}
            </p>
            <p className="text-sm">
              {restore.safety_backup_id ? t("failedSafe") : t("failedNoSafety")}
            </p>
            {restore.reference ? (
              <p className="pt-1 font-mono text-xs text-muted-foreground">
                {t("reference", { reference: restore.reference })}
              </p>
            ) : null}
          </div>
        </div>
        <Button variant="secondary" size="sm" onClick={onDismiss} className={cn("ml-14", NEUTRAL)}>
          <X className="size-4" />
          {t("dismiss")}
        </Button>
      </div>
    );
  }

  // Still in flight per the API, but not moving. Amber: nothing has reported a failure.
  if (stalled) {
    return (
      <div className="flex flex-col items-start gap-3 rounded-2xl border border-warning/30 bg-warning/5 p-5">
        <div className="flex items-start gap-3">
          <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-warning/15">
            <TriangleAlert className="size-6 text-warning" aria-hidden />
          </span>
          <div className="min-w-0 space-y-1">
            {/* Queued and running restores time out on different limits and
                mean different things, so each gets its own message. */}
            <p className="font-medium">{t(queued ? "stalledQueued" : "stalled")}</p>
            <p className="text-sm text-muted-foreground">
              {t(queued ? "stalledQueuedBody" : "stalledBody")}
            </p>
            {restore.reference ? (
              // The one thing worth quoting to whoever looks at the server.
              <p className="pt-1 font-mono text-xs text-muted-foreground">
                {t("reference", { reference: restore.reference })}
              </p>
            ) : null}
          </div>
        </div>
        <div className="ml-14 flex flex-wrap gap-2">
          <Button size="sm" onClick={checkAgain} disabled={checking}>
            <RefreshCw className={cn("size-4", checking && "animate-spin")} />
            {t("checkAgain")}
          </Button>
          <Button variant="secondary" size="sm" onClick={onDismiss} className={NEUTRAL}>
            <X className="size-4" />
            {t("dismiss")}
          </Button>
        </div>
      </div>
    );
  }

  // Queued: no step has run, so nothing on the site has changed yet.
  if (queued) {
    return (
      <div className="space-y-3 rounded-2xl border bg-muted/20 p-5">
        <div className="flex items-start gap-3">
          <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary/10">
            <Loader2 className="size-6 animate-spin text-primary" aria-hidden />
          </span>
          <div className="min-w-0 flex-1 space-y-1">
            <p className="font-medium">{t("queued")}</p>
            <p className="text-sm text-muted-foreground">{t("queuedBody")}</p>
          </div>
          <Button variant="secondary" size="sm" onClick={onDismiss} className={cn("shrink-0", NEUTRAL)}>
            <EyeOff className="size-4" />
            {t("hide")}
          </Button>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-3 rounded-2xl border bg-muted/20 p-5">
      <div className="flex items-start gap-3">
        <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary/10">
          <Loader2 className="size-6 animate-spin text-primary" aria-hidden />
        </span>
        <div className="min-w-0 flex-1 space-y-1">
          <p className="font-medium">{t("running")}</p>
          <p className="text-sm text-muted-foreground">
            {restore.current_step_title ?? t("starting")}
          </p>
        </div>
        {/* Hide, so a restore that never finishes does not leave an
            uncloseable banner. */}
        <Button variant="secondary" size="sm" onClick={onDismiss} className={cn("shrink-0", NEUTRAL)}>
          <EyeOff className="size-4" />
          {t("hide")}
        </Button>
      </div>

      <div className="ml-14 space-y-1.5">
        <Progress value={percent} className="h-1.5" />
        {total > 0 ? (
          <p className="text-xs text-muted-foreground">{t("step", { step, total })}</p>
        ) : null}
      </div>

      <p className="ml-14 text-xs text-muted-foreground">{t("dontLeave")}</p>
    </div>
  );
}
