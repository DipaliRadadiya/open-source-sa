import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { useFormatter, useNow } from "next-intl";
import { useSubjectTranslations } from "@/hooks/use-subject-translations";
import { parseApiWallClock } from "@/lib/format/api-date";
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

const POLL_MS = 2000;

const POLL_LIMIT_MS = 20 * 60 * 1000;

const QUEUED_LIMIT_MS = 2 * 60 * 1000;

const NEUTRAL =
  "border-transparent bg-background text-foreground shadow-xs hover:bg-muted dark:bg-secondary dark:hover:bg-muted";

export function RestoreProgress({
  restore: initial,
  applicationDomain,
  restoredSafetyCopy = false,
  onStatusChange,
  onDismiss,
  subject = "application",
  database = null,
}) {
  const t = useSubjectTranslations("backups.progress", "backups.database.progress", subject);
  const { refreshAndWait } = useRefresh();
  const router = useRouter();
  const [restore, setRestore] = useState(initial);
  const format = useFormatter();
  const now = useNow({ updateInterval: 30000 });
  const finishedAt = parseApiWallClock(restore?.finished_at);
  const finishedAgo = finishedAt
    ?
      format.relativeTime(Math.min(finishedAt.getTime(), now.getTime() - 1000), now)
    : restore?.finished_at_human ?? null;
  const [undoBackup, setUndoBackup] = useState(null);
  // Otherwise the banner would offer to undo the undo.
  const [wasUndo, setWasUndo] = useState(Boolean(restoredSafetyCopy));
  const [loadingUndo, setLoadingUndo] = useState(false);
  const [stalled, setStalled] = useState(false);
  const [round, setRound] = useState(0);
  const [checking, setChecking] = useState(false);
  const timer = useRef(null);
  const statusRef = useRef(onStatusChange);
  useEffect(() => {
    statusRef.current = onStatusChange;
  });

  const inFlight = RESTORE_IN_FLIGHT.includes(restore?.status);

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
        if (!RESTORE_IN_FLIGHT.includes(next.status)) router.refresh();
      } catch {
        // retry on the next poll
      }
    }

    timer.current = setInterval(poll, POLL_MS);
    const stop = setTimeout(() => {
      clearInterval(timer.current);
      setStalled(true);
    }, queued ? QUEUED_LIMIT_MS : POLL_LIMIT_MS);

    return () => {
      clearInterval(timer.current);
      clearTimeout(stop);
    };
  }, [inFlight, id, queued, router, round]);

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
        <div className="flex flex-col gap-3 rounded-2xl border border-success/30 bg-success/5 p-5 sm:flex-row sm:items-center">
          <div className="flex min-w-0 flex-1 items-start gap-3">
            <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-success/10">
              <CircleCheck className="size-6 text-success" aria-hidden />
            </span>
            <div className="min-w-0 space-y-1">
              <p className="font-medium">{wasUndo ? t("undone") : t("succeeded")}</p>
              <p className="text-sm text-muted-foreground" suppressHydrationWarning>
                {wasUndo
                  ? t("undoneBody")
                  : finishedAgo
                    ? t("succeededBody", { when: finishedAgo })
                    : t("succeededBodyPlain")}
              </p>
              {restore.safety_backup_id && restore.safety_copy !== false && !wasUndo ? (
                <p className="text-xs text-muted-foreground">{t("undoHint")}</p>
              ) : null}
            </div>
          </div>

          <div className="ms-14 flex shrink-0 flex-wrap gap-2 sm:ms-0">
            {restore.safety_backup_id && restore.safety_copy !== false && !wasUndo ? (
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
        </div>

        <RestoreDialog
          key={undoBackup?.id}
          backup={undoBackup}
          subject={subject}
          database={database}
          open={Boolean(undoBackup)}
          onOpenChange={(next) => (next ? null : setUndoBackup(null))}
          onStarted={(next) => {
            setUndoBackup(null);
            if (!next) return;
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
      <div className="flex flex-col gap-3 rounded-2xl border border-destructive/30 bg-destructive/5 p-5 sm:flex-row sm:items-start">
        <div className="flex min-w-0 flex-1 items-start gap-3">
          <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-destructive/10">
            <CircleAlert className="size-6 text-destructive" aria-hidden />
          </span>
          <div className="min-w-0 space-y-1">
            <p className="font-medium">{t("failed")}</p>
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
        <Button variant="secondary" size="sm" onClick={onDismiss} className={cn("ms-14 shrink-0 self-start sm:ms-0", NEUTRAL)}>
          <X className="size-4" />
          {t("dismiss")}
        </Button>
      </div>
    );
  }

  if (stalled) {
    return (
      <div className="flex flex-col gap-3 rounded-2xl border border-warning/30 bg-warning/5 p-5 sm:flex-row sm:items-center">
        <div className="flex min-w-0 flex-1 items-start gap-3">
          <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-warning/15">
            <TriangleAlert className="size-6 text-warning" aria-hidden />
          </span>
          <div className="min-w-0 space-y-1">
            <p className="font-medium">{t(queued ? "stalledQueued" : "stalled")}</p>
            <p className="text-sm text-muted-foreground">
              {t(queued ? "stalledQueuedBody" : "stalledBody")}
            </p>
            {restore.reference ? (
              <p className="pt-1 font-mono text-xs text-muted-foreground">
                {t("reference", { reference: restore.reference })}
              </p>
            ) : null}
          </div>
        </div>
        <div className="ms-14 flex shrink-0 flex-wrap gap-2 sm:ms-0">
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
        <Button variant="secondary" size="sm" onClick={onDismiss} className={cn("shrink-0", NEUTRAL)}>
          <EyeOff className="size-4" />
          {t("hide")}
        </Button>
      </div>

      <div className="sm:ml-14 space-y-1.5">
        <Progress value={percent} className="h-1.5" />
        {total > 0 ? (
          <p className="text-xs text-muted-foreground">{t("step", { step, total })}</p>
        ) : null}
      </div>

      <p className="sm:ml-14 text-xs text-muted-foreground">{t("dontLeave")}</p>
    </div>
  );
}
