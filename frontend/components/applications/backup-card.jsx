"use client";

import { useEffect, useState } from "react";
import Link from "@/components/ui/app-link";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import {
  Archive,
  ArrowRight,
  CalendarArrowUp,
  CalendarClock,
  CircleAlert,
  History,
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

// Same threshold the Backups screen uses before reporting a stalled queue.
const QUEUE_STALLED_MS = 3 * 60 * 1000;

// States use the Backups screen's vocabulary; a switched-off or manual target is `paused`.
export function BackupCard({
  applicationId,
  target,
  backups = [],
  failed = false,
  noneKept = false,
  canManage,
  href,
}) {
  const t = useTranslations("applications.backups");
  const { refreshAndWait } = useRefresh();
  const [starting, setStarting] = useState(false);
  // The newest backup id at the moment a run was started here, or null.
  const [queuedAfter, setQueuedAfter] = useState(null);
  const [stalled, setStalled] = useState(false);

  // A run the server is writing now, including ones started elsewhere.
  const busy = backups.some((backup) => BACKUP_IN_FLIGHT.includes(backup.status));
  // Before that: the POST answers 202, so the run is only a queued job for a few seconds.
  const queued = isBackupQueued(backups, queuedAfter);
  const inProgress = busy || queued;

  // Report a stall instead of spinning forever when the worker never picks it up.
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
    // Destructive: nothing to restore is the worst state this card can report.
    unprotected: { icon: ShieldOff, variant: "destructive" },
  }[state];
  const Icon = meta.icon;

  async function backUpNow() {
    setStarting(true);
    setStalled(false);
    try {
      await runBackupNow(applicationId);
      // The queued state ends as soon as the worker's row appears.
      setQueuedAfter(newestBackupId(backups));
      await refreshAndWait();
      toast.success(t("started"));
    } catch (error) {
      toast.error(apiMessage(error, t("startFailed")));
    } finally {
      setStarting(false);
    }
  }

  return (
    <Card>
      {/* Polls only while something is in progress; gives up after ten minutes. */}
      {inProgress ? <AutoRefresh intervalMs={5000} stopAfterMs={600000} /> : null}

      <CardHeader className="gap-1.5">
        <div className="min-w-0 space-y-1">
          <CardTitle as="h2" className="flex items-center gap-2 text-lg font-semibold">
            <Archive className="size-4 text-primary" />
            {t("title")}
          </CardTitle>
          <CardDescription>{t("description")}</CardDescription>
        </div>
        {/* A run in flight outranks the standing state, which is stale once it starts. */}
        {failed ? null : inProgress ? (
          <Badge variant="muted" className="w-fit gap-1.5 font-normal">
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

      <CardContent className="flex flex-1 flex-col p-0">
        {/* A failed read must not read as "no backups configured". */}
        {failed ? (
          <p className="px-(--card-spacing) text-sm text-muted-foreground">{t("loadFailed")}</p>
        ) : (
          <dl className="divide-y border-t text-sm">
            {target?.frequency_title ? (
              <div className="flex items-center gap-3 px-6 py-3">
                <CalendarClock className="size-4 shrink-0 text-muted-foreground" />
                <dt className="flex-1 font-medium">{t("schedule")}</dt>
                <dd className="text-right text-muted-foreground">{target.frequency_title}</dd>
              </div>
            ) : null}
            <div className="flex items-center gap-3 px-6 py-3">
              <History className="size-4 shrink-0 text-muted-foreground" />
              <dt className="flex-1 font-medium">{t("lastRun")}</dt>
              {/* Never blank: "never" is a real answer. */}
              <dd className="text-right text-muted-foreground">
                {!target?.last_run_at_human ? t("never") : noneKept ? t("noneKept") : target.last_run_at_human}
              </dd>
            </div>
            {target?.next_run_at_human && state === "protected" ? (
              <div className="flex items-center gap-3 px-6 py-3">
                <CalendarArrowUp className="size-4 shrink-0 text-muted-foreground" />
                <dt className="flex-1 font-medium">{t("nextRun")}</dt>
                <dd className="text-right text-muted-foreground">{target.next_run_at_human}</dd>
              </div>
            ) : null}
            {/* States the consequence; paused is called out because it looks set up. */}
            {state !== "protected" ? (
              <div className="px-6 py-2.5 text-xs text-muted-foreground">
                {state === "paused" ? t("pausedRisk") : t("unprotectedRisk")}
              </div>
            ) : null}
          </dl>
        )}

        {/* Covers the gap between the click and the first row appearing. */}
        {inProgress ? (
          <p
            role="status"
            className={cn(
              "mx-(--card-spacing) mt-(--card-spacing) flex items-start gap-2 rounded-lg px-3 py-2 text-sm",
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

        <div className="flex flex-wrap gap-2 px-(--card-spacing) pt-(--card-spacing)">
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
          {/* Primary when there is no target, except on a failed read. */}
          <Button asChild variant={!failed && !target ? "default" : "outline"} size="sm">
            <Link href={href} prefetch={false}>
              {target || failed ? t("manage") : t("setUp")}
              <ArrowRight className="size-4" />
            </Link>
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}
