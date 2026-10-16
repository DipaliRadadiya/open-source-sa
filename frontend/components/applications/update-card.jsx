"use client";

import { useEffect, useRef, useState } from "react";
import Link from "@/components/ui/app-link";
import { useFormatter, useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  ArrowUpCircle,
  CheckCircle2,
  CircleAlert,
  CircleCheck,
  History,
  Loader2,
  PackageCheck,
  RefreshCw,
} from "lucide-react";
import { useRefresh } from "@/hooks/use-refresh";
import { apiMessage } from "@/lib/api/error-message";
import { fetchApplicationUpdate, startApplicationUpdate } from "@/lib/api/application-update";
import { parseApiDate } from "@/lib/format/api-date";
import {
  backupIsFresh,
  parseBackupStamp,
  updateBlockedKey,
  updateCardState,
} from "@/lib/applications/app-update";
import { UPDATE_IN_FLIGHT } from "@/lib/schemas/application-update";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardAction, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Caution } from "@/components/ui/caution";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { CopyButton } from "@/components/ui/copy-button";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
import { FACT_LABEL } from "@/lib/theme/facts";

const POLL_MS = 5000;
// The backend releases a pending run after 60 minutes; stop asking a little later.
const POLL_GIVE_UP_MS = 65 * 60 * 1000;

const BADGE = {
  running: { variant: "muted", icon: Loader2, spin: true },
  failed: { variant: "destructive", icon: CircleAlert },
  available: { variant: "default", icon: ArrowUpCircle },
  up_to_date: { variant: "success", icon: CircleCheck },
  lookup_failed: { variant: "warning", icon: CircleAlert },
  version_unknown: { variant: "warning", icon: CircleAlert },
  not_active: { variant: "muted", icon: CircleAlert },
};

// Only for the six types the backend can update; `supported: false` renders nothing upstream.
export function UpdateCard({ appId, appTitle, initial, canManage, backupsHref }) {
  const t = useTranslations("applications.update");
  const format = useFormatter();
  const { refreshAndWait } = useRefresh();
  const [data, setData] = useState(initial);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [historyOpen, setHistoryOpen] = useState(false);
  const [starting, setStarting] = useState(false);
  const [checking, setChecking] = useState(false);
  // A newer server render (router.refresh) replaces the local copy; adjusted during
  // render rather than in an effect, as React recommends for props-derived state.
  const [seenInitial, setSeenInitial] = useState(initial);
  if (initial !== seenInitial) {
    setSeenInitial(initial);
    setData(initial);
  }

  const { update, history } = data;
  const last = history[0] ?? null;
  const state = updateCardState(update, history);
  const running = state === "running";
  const blockedKey = updateBlockedKey(update, { canManage });
  const badge = BADGE[state] ?? BADGE.up_to_date;
  const BadgeIcon = badge.icon;

  // Polls only this endpoint while a run is in flight, then refreshes the page once.
  const watchedRun = useRef(null);
  useEffect(() => {
    if (!running) return undefined;
    watchedRun.current ??= last?.id ?? null;
    const startedAt = Date.now();
    let stop = false;
    const id = setInterval(async () => {
      if (stop) return;
      if (Date.now() - startedAt > POLL_GIVE_UP_MS) {
        clearInterval(id);
        return;
      }
      try {
        const next = await fetchApplicationUpdate(appId);
        if (stop) return;
        setData(next);
        const run = next.history[0];
        if (run && !UPDATE_IN_FLIGHT.includes(run.status) && next.update.blocked_reason !== "running") {
          clearInterval(id);
          // Only announce runs this tab watched finish, not ones that ended before it opened.
          if (watchedRun.current === run.id) {
            if (run.status === "completed") {
              toast.success(t("toast.completed", { app: appTitle, version: run.to_version ?? "" }));
            } else {
              toast.error(run.reason_title ?? t("toast.failed", { app: appTitle }), { duration: 10000 });
            }
          }
          watchedRun.current = null;
          await refreshAndWait();
        }
      } catch {
        // A missed poll is retried on the next tick; the card keeps its last answer.
      }
    }, POLL_MS);
    return () => {
      stop = true;
      clearInterval(id);
    };
    // Restarts only when a run starts or ends, not on every poll.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [running, appId]);

  async function start() {
    setStarting(true);
    try {
      const run = await startApplicationUpdate(appId);
      watchedRun.current = run.id;
      setData((current) => ({
        update: { ...current.update, blocked_reason: "running" },
        history: [run, ...current.history.filter((item) => item.id !== run.id)],
      }));
      setConfirmOpen(false);
      toast.success(t("toast.started", { app: appTitle, version: run.to_version ?? update.latest_version ?? "" }));
    } catch (error) {
      const status = error.response?.status;
      const refused = error.response?.data?.errors?.update?.[0];
      setConfirmOpen(false);
      toast.error(status === 409 ? t("blocked.running") : refused ?? apiMessage(error, t("toast.startFailed")));
      // The state changed under us (409/422): show what the server says now.
      await checkAgain();
    } finally {
      setStarting(false);
    }
  }

  async function checkAgain() {
    setChecking(true);
    try {
      setData(await fetchApplicationUpdate(appId));
    } catch (error) {
      toast.error(apiMessage(error, t("toast.checkFailed")));
    } finally {
      setChecking(false);
    }
  }

  const relative = (stamp) => {
    const date = parseApiDate(stamp);
    return date ? format.relativeTime(date, new Date()) : null;
  };

  // The last FINISHED run: the one in flight is already said by the badge and the note.
  const finished = history.find((run) => !UPDATE_IN_FLIGHT.includes(run.status)) ?? null;
  const lastLine = finished
    ? t(finished.status === "completed" ? "last.completed" : "last.failed", {
        version: finished.to_version ?? "",
        when: relative(finished.finished_at ?? finished.created_at) ?? "—",
      })
    : t("last.never");

  const backupDate = parseBackupStamp(update.last_backup_at);
  const freshBackup = backupIsFresh(update.last_backup_at);

  const updateButton = (
    <Button
      size="sm"
      variant={state === "available" || state === "failed" ? "default" : "outline"}
      className="h-auto min-h-8 max-w-full py-1.5 text-center whitespace-normal"
      disabled={Boolean(blockedKey) || starting}
      disabledReason={blockedKey && blockedKey !== "up_to_date" ? t(`blocked.${blockedKey}`) : null}
      onClick={() => setConfirmOpen(true)}
    >
      <ArrowUpCircle className="size-4" />
      {update.update_available && update.latest_version
        ? t("updateTo", { version: update.latest_version })
        : t("update")}
    </Button>
  );

  return (
    <Card>
      <CardHeader className="items-center border-b">
        <CardTitle as="h2" className="flex flex-wrap items-center gap-2">
          {t("title", { app: appTitle })}
          <Badge variant={badge.variant} className="gap-1.5 font-normal">
            <BadgeIcon className={badge.spin ? "size-3 animate-spin" : "size-3"} />
            {t(`state.${state}`)}
          </Badge>
        </CardTitle>
        <CardAction className="row-span-1 flex flex-wrap justify-end gap-2 self-center">
          {state === "lookup_failed" ? (
            <Button variant="outline" size="sm" onClick={checkAgain} disabled={checking}>
              <RefreshCw className={checking ? "size-4 animate-spin" : "size-4"} />
              {t("checkAgain")}
            </Button>
          ) : null}
          {history.length ? (
            <Button variant="outline" size="sm" onClick={() => setHistoryOpen(true)}>
              <History className="size-4" />
              {t("history.open")}
            </Button>
          ) : null}
          {/* Hidden when there is nothing to do; shown greyed with the reason otherwise. */}
          {state === "up_to_date" || state === "not_active" || running ? null : updateButton}
        </CardAction>
      </CardHeader>

      <CardContent className="p-0">
        {/* Three facts side by side on a wide card, stacked on a phone. */}
        <dl className="-mt-(--card-spacing) grid divide-y text-sm sm:grid-cols-3 sm:divide-x sm:divide-y-0">
          <Fact label={t("installed")} mono>
            {update.installed_version ?? t("unknown")}
          </Fact>
          <Fact label={t("available")} mono={Boolean(update.latest_version)}>
            {update.latest_version
              ? update.latest_version
              : state === "lookup_failed"
                ? t("notChecked")
                : "—"}
          </Fact>
          <Fact label={t("lastUpdate")}>{lastLine}</Fact>
        </dl>

        {running ? (
          <p role="status" className="mx-(--card-spacing) mb-(--card-spacing) flex items-start gap-2 rounded-lg bg-muted/50 px-3 py-2 text-sm text-muted-foreground">
            <Loader2 className="mt-0.5 size-4 shrink-0 animate-spin text-primary" />
            <span>
              {t("runningNote", {
                version: last?.to_version ?? update.latest_version ?? "",
                when: relative(last?.started_at ?? last?.created_at) ?? "—",
              })}
            </span>
          </p>
        ) : null}

        {!running && last?.status === "failed" ? (
          <Caution
            tone="destructive"
            className="mx-(--card-spacing) mb-(--card-spacing)"
            action={last.reference ? <CopyButton value={last.reference} label={t("copyReference")} /> : null}
          >
            <p className="leading-relaxed">
              {last.reason_title ?? t("toast.failed", { app: appTitle })}
              {last.reference ? (
                <span className="ms-1 text-muted-foreground">
                  {t("reference", { reference: last.reference })}
                </span>
              ) : null}
            </p>
          </Caution>
        ) : null}

        {update.newer_major ? (
          <p className="border-t px-(--card-spacing) py-2.5 text-xs text-muted-foreground">
            {t("newerMajor", { version: update.newer_major })}
          </p>
        ) : null}
      </CardContent>

      <ConfirmDialog
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        icon={ArrowUpCircle}
        title={t("confirm.title", {
          app: appTitle,
          from: update.installed_version ?? "",
          to: update.latest_version ?? "",
        })}
        description={t("confirm.description")}
        cancelLabel={t("confirm.cancel")}
        confirmLabel={t("confirm.confirm")}
        pending={starting}
        onConfirm={start}
      >
        {/* The panel does not back up first, so the dialog says when the last one was. */}
        {freshBackup ? (
          <p className="flex items-start gap-2 rounded-lg border border-success/25 bg-success/5 px-3 py-2.5 text-sm">
            <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-success" />
            <span>{t("confirm.backupFresh", { when: format.relativeTime(backupDate, new Date()) })}</span>
          </p>
        ) : (
          <Caution
            size="md"
            action={
              backupsHref ? (
                <Button asChild variant="outline" size="sm" className="bg-card">
                  <Link href={backupsHref} prefetch={false}>
                    <PackageCheck className="size-4" />
                    {t("confirm.backUpFirst")}
                  </Link>
                </Button>
              ) : null
            }
          >
            <p className="leading-relaxed">
              {backupDate
                ? t("confirm.backupOld", { when: format.relativeTime(backupDate, new Date()) })
                : t("confirm.backupNone")}
            </p>
          </Caution>
        )}
      </ConfirmDialog>

      <Sheet open={historyOpen} onOpenChange={setHistoryOpen}>
        <SheetContent className="w-full sm:max-w-md">
          <SheetHeader>
            <SheetTitle>{t("history.title", { app: appTitle })}</SheetTitle>
            <SheetDescription>{t("history.description")}</SheetDescription>
          </SheetHeader>
          <ul className="min-h-0 flex-1 divide-y overflow-y-auto px-4 pb-4">
            {history.map((run) => (
              <li key={run.id} className="space-y-1 py-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <span className="font-mono text-sm">
                    {run.from_version ?? "?"} → {run.to_version ?? "?"}
                  </span>
                  <Badge
                    variant={
                      run.status === "completed"
                        ? "success"
                        : run.status === "failed"
                          ? "destructive"
                          : "muted"
                    }
                    className="font-normal"
                  >
                    {run.status_title ?? run.status}
                  </Badge>
                </div>
                <p className="text-xs text-muted-foreground">
                  {[relative(run.finished_at ?? run.started_at ?? run.created_at), run.user ? t("history.by", { user: run.user }) : null]
                    .filter(Boolean)
                    .join(" · ")}
                </p>
                {run.status === "failed" && run.reason_title ? (
                  <p className="text-xs text-destructive">
                    {run.reason_title}
                    {run.reference ? <span className="ms-1 font-mono text-muted-foreground">{run.reference}</span> : null}
                  </p>
                ) : null}
              </li>
            ))}
          </ul>
        </SheetContent>
      </Sheet>
    </Card>
  );
}

function Fact({ label, mono = false, children }) {
  return (
    <div className="space-y-0.5 px-(--card-spacing) py-3">
      <dt className={FACT_LABEL}>{label}</dt>
      <dd className={mono ? "font-mono text-sm font-medium" : "text-sm"}>{children}</dd>
    </div>
  );
}
