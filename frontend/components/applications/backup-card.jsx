"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import {
  Archive,
  ArrowRight,
  CircleAlert,
  Loader2,
  ShieldCheck,
  ShieldOff,
  PauseCircle,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { runBackupNow } from "@/lib/api/backups";
import { apiMessage } from "@/lib/api/error-message";
import { BACKUP_IN_FLIGHT } from "@/lib/schemas/backup";
import { isBackupQueued, newestBackupId } from "@/lib/backups/queued";
import { AutoRefresh } from "@/components/ui/auto-refresh";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

// The same three minutes the Backups screen waits before admitting the worker
// has not taken the job.
const QUEUE_STALLED_MS = 3 * 60 * 1000;

/**
 * Whether this site could be recovered, and the one action worth having here.
 *
 * The three states are the Backups screen's own vocabulary, deliberately —
 * "protected", "paused" and "not protected" have to mean the same thing in both
 * places or the panel contradicts itself. A target that exists but is switched
 * off, or set to manual, backs nothing up: that is `paused`, and it is the state
 * worth naming because it looks configured and is not.
 *
 * "Back up now" is here because it is the only backup action that needs no
 * decisions — one call, no form. Setting a schedule, choosing a destination or
 * restoring all involve choices, and those live on the Backups screen.
 */
export function BackupCard({
  applicationId,
  target,
  backups = [],
  failed = false,
  canManage,
  href,
}) {
  const t = useTranslations("applications.backups");
  const router = useRouter();
  const [starting, setStarting] = useState(false);
  // The newest backup id at the moment a run was started here, or null.
  const [queuedAfter, setQueuedAfter] = useState(null);
  const [stalled, setStalled] = useState(false);

  // A row the server is writing right now. This is the half that also catches a
  // scheduled run, or one somebody else started — neither of which this card
  // could see before, because it only ever knew about its own click.
  const busy = backups.some((backup) => BACKUP_IN_FLIGHT.includes(backup.status));
  // And the half before that: the POST answers 202 with the target, so for the
  // first few seconds the run exists as a queued job and nothing else.
  const queued = isBackupQueued(backups, queuedAfter);
  const inProgress = busy || queued;

  // Say so rather than spin forever — a worker that never picks the job up
  // otherwise looks identical to one that is about to.
  useEffect(() => {
    if (!queued || stalled) return undefined;
    const id = setTimeout(() => setStalled(true), QUEUE_STALLED_MS);
    return () => clearTimeout(id);
  }, [queued, stalled]);

  const state = !target
    ? "unprotected"
    : !target.enabled || target.frequency === "manual"
      ? "paused"
      : "protected";

  const meta = {
    protected: { icon: ShieldCheck, variant: "success" },
    paused: { icon: PauseCircle, variant: "warning" },
    unprotected: { icon: ShieldOff, variant: "secondary" },
  }[state];
  const Icon = meta.icon;

  async function backUpNow() {
    setStarting(true);
    setStalled(false);
    try {
      await runBackupNow(applicationId);
      toast.success(t("started"));
      // Remember where the list stood, so the queued state ends itself the
      // moment the worker's row appears.
      setQueuedAfter(newestBackupId(backups));
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("startFailed")));
    } finally {
      setStarting(false);
    }
  }

  return (
    <Card>
      {/* Only while something is actually happening — a dashboard nobody is
          waiting on should not be polling. Gives up after ten minutes. */}
      {inProgress ? <AutoRefresh intervalMs={5000} stopAfterMs={600000} /> : null}

      <CardHeader className="gap-1.5">
        <div className="min-w-0 space-y-1">
          <CardTitle as="h2" className="flex items-center gap-2 text-lg font-semibold">
            <Archive className="size-4 text-primary" />
            {t("title")}
          </CardTitle>
          <CardDescription>{t("description")}</CardDescription>
        </div>
        {/* A run in flight outranks the standing state: "Protected · last
            backup 18 hours ago" is stale the second one starts, and it is the
            only thing on this card that changes while somebody watches it. */}
        {failed ? null : inProgress ? (
          <Badge variant="secondary" className="w-fit gap-1.5 font-normal">
            <Loader2 className="size-3 animate-spin" />
            {t("state.running")}
          </Badge>
        ) : (
          <Badge variant={meta.variant} className="w-fit gap-1.5 font-normal">
            <Icon className="size-3" />
            {t(`state.${state}`)}
          </Badge>
        )}
      </CardHeader>

      {/* gap rather than space-y: space-y sets margin-top on children via a
          compound selector that would outrank any margin set here. flex-1 lets
          the card fill its stretched row, and the leftover collects as padding
          under the last line — not as a gap above a button floated to the card
          foot, which read as a rendering fault on any site with little to
          report. */}
      <CardContent className="flex flex-1 flex-col gap-3">
        {/* A failed read is not "no backups configured" — that would tell
            somebody their site is unprotected on the evidence of one request. */}
        {failed ? (
          <p className="text-sm text-muted-foreground">{t("loadFailed")}</p>
        ) : (
          <dl className="space-y-1.5 text-sm">
            {target?.frequency_title ? (
              <div className="flex justify-between gap-3">
                <dt className="text-muted-foreground">{t("schedule")}</dt>
                <dd className="text-right">{target.frequency_title}</dd>
              </div>
            ) : null}
            <div className="flex justify-between gap-3">
              <dt className="text-muted-foreground">{t("lastRun")}</dt>
              {/* Never blank: an empty cell reads as a rendering fault, and
                  "never" is a real and important answer here. */}
              <dd className="text-right">{target?.last_run_at_human ?? t("never")}</dd>
            </div>
            {target?.next_run_at_human && state === "protected" ? (
              <div className="flex justify-between gap-3">
                <dt className="text-muted-foreground">{t("nextRun")}</dt>
                <dd className="text-right">{target.next_run_at_human}</dd>
              </div>
            ) : null}
          </dl>
        )}

        {/* The consequence, not the label. "Not protected" is a status; what it
            means is that if this site is lost there is nothing to put back, and
            that is the fact somebody needs before deciding it can wait. Paused
            gets its own line because it is the deceptive one — it looks set up,
            and the last copy is ageing. */}
        {!failed && state !== "protected" ? (
          <p className="text-sm text-muted-foreground">
            {state === "paused" ? t("pausedRisk") : t("unprotectedRisk")}
          </p>
        ) : null}

        {/* The gap the toast could not cover. Between the click and the first
            row appearing, the card was byte-identical to the one the person was
            looking at before they pressed the button. */}
        {inProgress ? (
          <p
            role="status"
            className={cn(
              "flex items-start gap-2 rounded-lg px-3 py-2 text-sm",
              stalled ? "bg-warning/10 text-foreground" : "bg-muted/50 text-muted-foreground",
            )}
          >
            {stalled ? (
              <CircleAlert className="mt-0.5 size-4 shrink-0 text-warning" />
            ) : (
              <Loader2 className="mt-0.5 size-4 shrink-0 animate-spin text-primary" />
            )}
            <span>{stalled ? t("queuedStalled") : queued ? t("queuedNote") : t("runningNote")}</span>
          </p>
        ) : null}

        <div className="flex flex-wrap gap-2">
          {canManage && target ? (
            <Button
              variant="outline"
              size="sm"
              disabled={starting || inProgress}
              disabledReason={!starting && inProgress ? t("alreadyRunning") : null}
              onClick={backUpNow}
            >
              {starting || inProgress ? <Loader2 className="size-4 animate-spin" /> : null}
              {starting || inProgress ? t("starting") : t("backUpNow")}
            </Button>
          ) : null}
          {/* Setting one up is the point of the card when there is no target;
              a ghost link for the only thing worth doing here buries it. */}
          <Button asChild variant={!failed && !target ? "default" : "ghost"} size="sm">
            <Link href={href} prefetch={false}>
              {target ? t("manage") : t("setUp")}
              <ArrowRight className="size-4" />
            </Link>
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}
