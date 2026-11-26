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
import { shownSizeBytes } from "@/lib/applications/size-measurement";

const QUEUE_STALLED_MS = 3 * 60 * 1000;

export function BackupCard({
  applicationId,
  target,
  backups = [],
  failed = false,
  noneKept = false,
  canManage,
  href,
  application = null,
  databases = null,
}) {
  const t = useTranslations("applications.backups");
  const format = useFormatter();
  const { refreshAndWait } = useRefresh();
  const [starting, setStarting] = useState(false);
  const [queuedAfter, setQueuedAfter] = useState(null);
  const [stalled, setStalled] = useState(false);

  const busy = backups.some((backup) => BACKUP_IN_FLIGHT.includes(backup.status));
  const queued = isBackupQueued(backups, queuedAfter);
  const inProgress = busy || queued;

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
    unprotected: { icon: ShieldOff, variant: "destructive" },
  }[state];
  const Icon = meta.icon;

  async function backUpNow() {
    setStarting(true);
    setStalled(false);
    try {
      await runBackupNow(applicationId);
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
      {inProgress ? <AutoRefresh intervalMs={5000} stopAfterMs={600000} /> : null}

      <CardHeader className="items-center border-b">
        <CardTitle icon={Archive} as="h2" className="flex flex-wrap items-center gap-2">
          {t("title")}
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
              className="h-auto min-h-8 max-w-full py-1.5 text-center whitespace-normal"
              disabled={starting || inProgress}
              disabledReason={!starting && inProgress ? t("alreadyRunning") : null}
              onClick={backUpNow}
            >
              {starting || inProgress ? <Loader2 className="size-4 animate-spin" /> : null}
              {starting || inProgress ? t("starting") : t("backUpNow")}
            </Button>
          ) : null}
          <Button asChild variant={!failed && !target ? "default" : "outline"} size="sm" className="h-auto min-h-8 max-w-full py-1.5 text-center whitespace-normal">
            <Link href={href} prefetch={false}>
              {target || failed ? t("manage") : t("setUp")}
              <ArrowRight className="size-4" />
            </Link>
          </Button>
        </CardAction>
      </CardHeader>

      <CardContent className="p-0">
        {failed ? (
          <p className="px-(--card-spacing) text-sm text-muted-foreground">{t("loadFailed")}</p>
        ) : (
          <dl className="-mt-(--card-spacing) divide-y text-sm">
            <div className="flex items-center gap-3 px-(--card-spacing) py-3">
              <CalendarClock className="size-4 shrink-0 text-muted-foreground" />
              <dt className="flex-1 font-medium">{t("schedule")}</dt>
              <dd className="text-right text-muted-foreground">{target?.frequency_title ?? t("notSetUp")}</dd>
            </div>
            <div className="flex items-center gap-3 px-(--card-spacing) py-3">
              <History className="size-4 shrink-0 text-muted-foreground" />
              <dt className="flex-1 font-medium">{t("lastRun")}</dt>
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
                  {formatBytes(shownSizeBytes(application), format) ?? "—"}
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
        {!failed && state === "paused" ? (
          <p className="border-t px-(--card-spacing) py-2.5 text-xs text-muted-foreground">{t("pausedRisk")}</p>
        ) : null}

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
