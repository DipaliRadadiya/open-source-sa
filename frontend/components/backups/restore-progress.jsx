import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { CircleAlert, CircleCheck, Loader2, RefreshCw, TriangleAlert, Undo2 } from "lucide-react";
import { RESTORE_IN_FLIGHT } from "@/lib/schemas/backup";
import { reasonText } from "@/lib/backups/reason";
import { fetchBackup, fetchRestore } from "@/lib/api/backups";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { Progress } from "@/components/ui/progress";
import { RestoreDialog } from "@/components/backups/restore-dialog";

/** The backend polls comfortably at 120/min; every 2s is well inside that. */
const POLL_MS = 2000;

/** Give up after 20 minutes; a restore that has not moved by then is stuck. */
const POLL_LIMIT_MS = 20 * 60 * 1000;

/**
 * A restore that has not even STARTED is a different wait, and a much shorter
 * one: it is sitting on the queue waiting for a worker, which takes seconds.
 * Judging it by the 20-minute rule meant a restore whose worker never existed
 * claimed to be under way for twenty minutes before admitting otherwise.
 */
const QUEUED_LIMIT_MS = 2 * 60 * 1000;

/**
 * A restore, while it happens and after it finishes.
 *
 * A toast would be wrong here: this is minutes long, it is the most
 * consequential thing the panel does, and the user has nothing to do but
 * watch. The named steps come from the API (`step_number` / `total_steps`), so
 * the bar is real rather than a spinner pretending — and one of those steps is
 * "Taking a safety copy", which is what turns the promise made in the
 * confirmation dialog into something the user watches happen.
 */
export function RestoreProgress({ restore: initial, applicationDomain, onDismiss }) {
  const t = useTranslations("backups.progress");
  const router = useRouter();
  const [restore, setRestore] = useState(initial);
  const [undoBackup, setUndoBackup] = useState(null);
  const [loadingUndo, setLoadingUndo] = useState(false);
  // Set when polling gives up: the restore is still `pending`/`running` as far
  // as the API is concerned, but nothing has moved for a long time.
  const [stalled, setStalled] = useState(false);
  const timer = useRef(null);

  const inFlight = RESTORE_IN_FLIGHT.includes(restore?.status);

  // Queued, not started: the API reports `pending` with no `started_at`, which
  // means the worker has not touched the site. Worth separating, because
  // "Restoring the site" over a restore that has not begun tells someone their
  // live site is being overwritten when nothing has happened to it at all.
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
        // The site's files and database just changed underneath every other
        // panel screen; refresh so nothing keeps showing the old world.
        if (!RESTORE_IN_FLIGHT.includes(next.status)) router.refresh();
      } catch {
        // A blip mid-restore is not worth alarming anyone about — the next
        // tick picks it up, and the restore runs on the server regardless.
      }
    }

    timer.current = setInterval(poll, POLL_MS);
    // A restore whose worker never picks it up stays `pending` forever, and
    // this would have kept asking every 2 seconds for as long as the tab was
    // open. The endpoint is built to be polled, not to be polled indefinitely
    // by a page nobody is watching finish.
    const stop = setTimeout(() => {
      clearInterval(timer.current);
      // Say so, rather than going quiet. A spinner that has silently stopped
      // spinning towards anything is the worst version of this screen: it
      // still claims work is happening.
      setStalled(true);
    }, queued ? QUEUED_LIMIT_MS : POLL_LIMIT_MS);

    return () => {
      clearInterval(timer.current);
      clearTimeout(stop);
    };
  }, [inFlight, id, queued, router]);

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
      setUndoBackup({ ...backup, application_domain: applicationDomain });
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
              <p className="font-medium">{t("succeeded")}</p>
              <p className="text-sm text-muted-foreground">
                {restore.finished_at_human
                  ? t("succeededBody", { when: restore.finished_at_human })
                  : t("succeededBodyPlain")}
              </p>
            </div>
          </div>

          {/* The way back. Nobody else in this class of product has one, and
              burying it in a table row would waste the only thing that makes
              a wrong restore survivable. */}
          <div className="ml-14 flex flex-wrap gap-2">
            {restore.safety_backup_id ? (
              <Button variant="outline" onClick={openUndo} disabled={loadingUndo}>
                {loadingUndo ? (
                  <Loader2 className="size-4 animate-spin" />
                ) : (
                  <Undo2 className="size-4" />
                )}
                {t("undo")}
              </Button>
            ) : null}
            <Button variant="ghost" onClick={onDismiss}>
              {t("dismiss")}
            </Button>
          </div>

          {restore.safety_backup_id ? (
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
            if (next) setRestore(next);
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
            {/* A translated key naming the step that failed — never raw
                stderr, which the backend deliberately does not send. */}
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
        <Button variant="outline" onClick={onDismiss} className="ml-14">
          {t("dismiss")}
        </Button>
      </div>
    );
  }

  // Still in flight by the API's reckoning, but it has not moved for a long
  // time. Amber, not red: nothing has been reported as broken, and the site
  // may well be mid-restore — but a spinner that says "in progress" forever
  // is the screen telling a comfortable lie.
  if (stalled) {
    return (
      <div className="flex flex-col items-start gap-3 rounded-2xl border border-warning/30 bg-warning/5 p-5">
        <div className="flex items-start gap-3">
          <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-warning/15">
            <TriangleAlert className="size-6 text-warning" aria-hidden />
          </span>
          <div className="min-w-0 space-y-1">
            {/* Two timers, so two messages. There was one, hardcoded to "20
                minutes" — but a restore still sitting on the queue gives up
                after two, so it announced a twenty-minute wait it had not
                waited. Worse, it said "it may still be running on the server"
                over a restore the worker never picked up: the queued banner
                one state earlier correctly says nothing has been changed, and
                this replaced that with a reason to panic. */}
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
          <Button variant="outline" onClick={() => router.refresh()}>
            <RefreshCw className="size-4" />
            {t("checkAgain")}
          </Button>
          <Button variant="ghost" onClick={onDismiss}>
            {t("dismiss")}
          </Button>
        </div>
      </div>
    );
  }

  // Queued: no step has run, so there is no progress to draw and — the part
  // that matters — nothing on the site has changed yet. Saying so is the whole
  // point of this branch.
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
          <Button variant="ghost" size="sm" onClick={onDismiss} className="shrink-0">
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
        {/* Hiding it entirely means a restore that never finishes leaves a
            banner nobody can clear. Ghost, and off to the side, so it does not
            compete with the thing they are watching. */}
        <Button variant="ghost" size="sm" onClick={onDismiss} className="shrink-0">
          {t("hide")}
        </Button>
      </div>

      <div className="ml-14 space-y-1.5">
        <Progress value={percent} className="h-1.5" />
        {total > 0 ? (
          <p className="text-xs text-muted-foreground">{t("step", { step, total })}</p>
        ) : null}
      </div>

      {/* Said while they wait, because this is the minute in which someone
          decides whether they trust the feature. */}
      <p className="ml-14 text-xs text-muted-foreground">{t("dontLeave")}</p>
    </div>
  );
}
