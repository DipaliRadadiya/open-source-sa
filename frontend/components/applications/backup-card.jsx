"use client";

import { useEffect, useState } from "react";
import Link from "@/components/ui/app-link";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useFormatter, useTranslations } from "next-intl";
import { formatBytes } from "@/lib/format/bytes";
import {
  ArrowRight,
  Archive,
  CalendarArrowUp,
  Database,
  Folder,
  HardDrive,
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
import { Card, CardAction, CardContent, CardHeader, CardTitle } from "@/components/ui/card";

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
  // For the rows: what a backup holds (unprotected) — the site's size and its databases.
  application = null,
  databases = null,
}) {
  const t = useTranslations("applications.backups");
  const format = useFormatter();
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

      {/* State beside the title and the buttons in the header, like the other cards here:
          a footer strip held one button and was mostly empty (7 Oct). */}
      <CardHeader className="items-center border-b">
        <CardTitle as="h2" className="flex flex-wrap items-center gap-2">
          {t("title")}
          {/* A run in flight outranks the standing state, which is stale once it starts. */}
          {failed ? null : inProgress ? (
            <Badge variant="muted" className="gap-1.5 font-normal">
              <Loader2 className="size-3 animate-spin" />
              {t("state.running")}
            </Badge>
          ) : (
            <Badge variant={meta.variant} className="gap-1.5 font-normal">
              <Icon className="size-3" />
              {t(`state.${state}`)}
            </Badge>
          )}
        </CardTitle>
        <CardAction className="row-span-1 flex flex-wrap justify-end gap-2 self-center">
          {canManage && target ? (
            <Button
              variant="outline"
              size="sm"
              // Wraps in a narrow card: some locales are longer than the card (ru, at 1280).
              className="h-auto min-h-8 max-w-full py-1.5 text-center whitespace-normal"
              disabled={starting || inProgress}
              disabledReason={!starting && inProgress ? t("alreadyRunning") : null}
              onClick={backUpNow}
            >
              {starting || inProgress ? <Loader2 className="size-4 animate-spin" /> : null}
              {starting || inProgress ? t("starting") : t("backUpNow")}
            </Button>
          ) : null}
          {/* Primary when there is no target, except on a failed read. */}
          <Button asChild variant={!failed && !target ? "default" : "outline"} size="sm" className="h-auto min-h-8 max-w-full py-1.5 text-center whitespace-normal">
            <Link href={href} prefetch={false}>
              {target || failed ? t("manage") : t("setUp")}
              <ArrowRight className="size-4" />
            </Link>
          </Button>
        </CardAction>
      </CardHeader>

      <CardContent className="p-0">
        {/* A failed read must not read as "no backups configured". */}
        {failed ? (
          <p className="px-(--card-spacing) text-sm text-muted-foreground">{t("loadFailed")}</p>
        ) : (
          <dl className="-mt-(--card-spacing) divide-y text-sm">
            {/* Always shown, so the card has the same rows whatever its state. */}
            <div className="flex items-center gap-3 px-(--card-spacing) py-3">
              <CalendarClock className="size-4 shrink-0 text-muted-foreground" />
              <dt className="flex-1 font-medium">{t("schedule")}</dt>
              <dd className="text-right text-muted-foreground">{target?.frequency_title ?? t("notSetUp")}</dd>
            </div>
            <div className="flex items-center gap-3 px-(--card-spacing) py-3">
              <History className="size-4 shrink-0 text-muted-foreground" />
              <dt className="flex-1 font-medium">{t("lastRun")}</dt>
              {/* Never blank: "never" is a real answer. */}
              {/* The newest run failing is the one fact that must not read as routine. */}
              {target && backups[0]?.status === "failed" && backups[0]?.created_at_human ? (
                <dd className="text-right font-medium text-destructive">{t("lastFailed", { when: backups[0].created_at_human })}</dd>
              ) : (
                <dd className="text-right text-muted-foreground">
                  {!target?.last_run_at_human ? t("never") : noneKept ? t("noneKept") : target.last_run_at_human}
                </dd>
              )}
            </div>
            {target?.next_run_at_human && state === "protected" ? (
              <Row icon={CalendarArrowUp} label={t("nextRun")}>{target.next_run_at_human}</Row>
            ) : null}
            {/* Five rows whatever the state, beside Security's five (22 Nov: the card stood
                half empty). Set up: where they go and how many are kept. Not set up: what a
                backup would hold, so the size of the risk is on the screen. */}
            {target ? (
              <>
                <Row icon={HardDrive} label={t("storedOn")}>{target.storage_destination_name ?? "—"}</Row>
                <Row icon={Archive} label={t("keeps")}>
                  {target.retention_count ? t("keepsCount", { count: target.retention_count }) : "—"}
                </Row>
              </>
            ) : (
              <>
                <Row icon={Folder} label={t("filesSize")}>
                  {formatBytes(application?.directory_size_bytes, format) ?? "—"}
                </Row>
                <Row icon={Database} label={t("databaseRow")}>
                  {databases === null
                    ? "—"
                    : databases.length > 0
                    ? databases.map((database) => [database.name, database.size_human].filter(Boolean).join(" · ")).join(", ")
                    : t("noDatabase")}
                </Row>
              </>
            )}
          </dl>
        )}
        {!failed && state === "unprotected" && application?.name ? (
          <p className="flex items-start gap-2 border-t px-(--card-spacing) py-3 text-sm text-destructive">
            <CircleAlert className="mt-0.5 size-4 shrink-0" />
            {t("atRisk", { name: application.name })}
          </p>
        ) : null}
        {/* Paused is called out because it looks set up. Outside the <dl>: a note is not a
            term/value pair. */}
        {!failed && state === "paused" ? (
          <p className="border-t px-(--card-spacing) py-2.5 text-xs text-muted-foreground">{t("pausedRisk")}</p>
        ) : null}

        {/* Covers the gap between the click and the first row appearing. */}
        {inProgress ? (
          <p
            role="status"
            className={cn(
              "mx-(--card-spacing) mt-3 flex items-start gap-2 rounded-lg px-3 py-2 text-sm",
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
      </CardContent>
    </Card>
  );
}

function Row({ icon: Icon, label, children }) {
  return (
    <div className="flex items-center gap-3 px-(--card-spacing) py-3">
      <Icon className="size-4 shrink-0 text-muted-foreground" />
      <dt className="flex-1 font-medium">{label}</dt>
      <dd className="min-w-0 truncate text-right text-muted-foreground">{children}</dd>
    </div>
  );
}
