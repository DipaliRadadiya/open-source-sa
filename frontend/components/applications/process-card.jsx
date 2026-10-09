"use client";

import { cn } from "@/lib/utils";
import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useFormatter, useTranslations } from "next-intl";
import { toast } from "sonner";
import { ArrowRightLeft, Loader2, Play, RotateCw, Square } from "lucide-react";
import { controlApplicationProcess } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardAction, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { formatBytes } from "@/lib/format/bytes";
import { ConvertSupervisorDialog } from "@/components/applications/convert-supervisor-dialog";

const STATE_VARIANT = { active: "success", failed: "destructive", activating: "warning" };

// Spelled out, not `${action}ed` ("stoped"); literal keys stay greppable.
const DONE_KEY = { start: "started", stop: "stopped", restart: "restarted" };

// A new git site is `active` with a never-started process: "deploy to start",
// not a fault, so not red.
export function ProcessCard({ application, canManage = false, className }) {
  const t = useTranslations("applications.process");
  const tApp = useTranslations("applications");
  const format = useFormatter();
  const { refreshThen } = useRefresh();
  const [pending, setPending] = useState(null);
  const [confirmStop, setConfirmStop] = useState(false);
  const [converting, setConverting] = useState(false);
  // systemd records a stopped Node process as "failed" (it exits on SIGTERM),
  // so the expected outcome of the last click decides how it reads.
  const [expected, setExpected] = useState(null);

  const process = application.process ?? {};
  const rawState = process.state ?? "unknown";
  const state =
    expected === "stopped" && rawState === "failed"
      ? "inactive"
      : expected === "running" && rawState !== "active"
        ? "activating"
        : rawState;
  const stateLabel =
    state === "active"
      ? tApp("status.active")
      : state === "inactive"
        ? tApp("markers.processStopped")
        : state === "failed"
          ? tApp("markers.processFailed")
          : state === "activating"
            ? tApp("details.starting")
            : state;
  const memory =
    formatBytes(process.memory, format) ??
    (typeof process.memory === "string" && process.memory.trim()
      ? process.memory.trim()
      : "—");
  const notStartedYet = state !== "active" && !application.deployed;

  // Adopted from the old panel and still run by its PM2 daemon. A supported
  // state, not a broken one — so it is labelled rather than warned about, and
  // the only thing offered is the choice to move.
  const adopted = application.supervisor_mode === "pm2";
  const instances = process.instances ?? null;
  const online = process.online ?? null;

  // Only worth a line when there is more than one, and phrased as a fraction
  // only when some are down: "3 of 4" is a problem someone should see, and
  // "4 of 4" is noise that makes the real case harder to spot.
  const workers =
    instances && instances > 1
      ? online !== null && online < instances
        ? t("workersDegraded", { online, total: instances })
        : t("workersCount", { total: instances })
      : null;

  async function run(action) {
    setPending(action);
    try {
      await controlApplicationProcess(application.id, action);
      setConfirmStop(false);
      setExpected(action === "stop" ? "stopped" : "running");
      // Busy until the page has the new state, and the toast with it.
      refreshThen(() => {
        toast.success(t(DONE_KEY[action]));
        setPending(null);
        if (action !== "stop") setExpected(null);
      });
    } catch (error) {
      toast.error(apiMessage(error, t("failed")));
      setPending(null);
    }
  }

  const facts = [
    // No "State" row: the pill in the header already says it.
    // `since` is the last start time, meaningless on a stopped process.
    { label: t("since"), value: state === "active" ? formatSince(process.since, format) : null },
    { label: t("memory"), value: memory },
    { label: t("restarts"), value: process.restarts },
    { label: t("workers"), value: workers },
  ].filter((fact) => fact.value !== null && fact.value !== undefined && fact.value !== "");

  return (
    // `#process`: the status tile jumps here when the process has stopped.
    <Card id="process" className={cn("@container/process scroll-mt-[calc(var(--app-chrome,4rem)_+_1rem)]", className)}>
      {/* State beside the title and buttons in the header, like the other cards on this page. */}
      <CardHeader className="items-center border-b">
        <CardTitle as="h2" className="flex flex-wrap items-center gap-2">
          {t("title")}
          {/* `muted` is the STATE pill; `secondary` below is a label. */}
          <Badge variant={STATE_VARIANT[state] ?? "muted"} className="font-normal">
            {stateLabel}
          </Badge>
          {adopted ? (
            <Badge variant="secondary" className="font-normal">
              {t("supervisorPm2")}
            </Badge>
          ) : null}
        </CardTitle>
        {canManage ? (
          <CardAction className="row-span-1 flex flex-wrap justify-end gap-2 self-center">
            {[
              // Each disabled action says why; Stop is red as on the Services page.
              { action: "start", icon: Play, variant: "default", reason: state === "active" ? t("alreadyRunning") : null },
              { action: "restart", icon: RotateCw, variant: "outline", reason: state === "active" ? null : t("notRunning") },
              { action: "stop", icon: Square, variant: "destructive", reason: state === "active" ? null : t("notRunning") },
            ].map(({ action, icon: Icon, variant, reason }) => (
              <Button
                key={action}
                size="sm"
                variant={variant}
                // Stop takes the application offline, so it asks first.
                onClick={() => (action === "stop" ? setConfirmStop(true) : run(action))}
                disabled={Boolean(pending) || Boolean(reason)}
                disabledReason={!pending ? reason : null}
              >
                {pending === action ? (
                  <Loader2 className="size-3.5 animate-spin" />
                ) : (
                  <Icon className="size-3.5" />
                )}
                {t(action)}
              </Button>
            ))}

            {adopted ? (
              <Button
                size="sm"
                variant="outline"
                onClick={() => setConverting(true)}
                disabled={Boolean(pending)}
              >
                <ArrowRightLeft className="size-3.5" />
                {t("convert.action")}
              </Button>
            ) : null}
          </CardAction>
        ) : null}
      </CardHeader>
      <CardContent className="space-y-4">
        {notStartedYet ? (
          <p className="text-sm text-muted-foreground">{t("deployToStart")}</p>
        ) : null}

        {/* Three across when the card is wide; in a narrow column the start time
            takes its own row so the date is not cut off. */}
        <dl className="grid grid-cols-2 gap-2 [&>*:first-child]:col-span-2 @xl/process:grid-cols-3 @xl/process:[&>*:first-child]:col-span-1">
          {facts.map((fact) => (
            <div key={fact.label} className="min-w-0 rounded-lg bg-muted/40 px-3 py-2 ring-1 ring-border/60">
              <dt className="text-xs text-muted-foreground">{fact.label}</dt>
              <dd className="truncate text-sm font-medium tabular-nums">{fact.value}</dd>
            </div>
          ))}
        </dl>

        <ConfirmDialog
          open={confirmStop}
          onOpenChange={(next) => !pending && setConfirmStop(next)}
          icon={Square}
          tone="destructive"
          title={t("stopTitle", { name: application.name })}
          description={t("stopBody")}
          cancelLabel={t("cancel")}
          confirmLabel={pending === "stop" ? t("stopping") : t("stop")}
          pending={pending === "stop"}
          onConfirm={() => run("stop")}
        />
      </CardContent>

      {adopted ? (
        <ConvertSupervisorDialog
          application={application}
          open={converting}
          onOpenChange={setConverting}
        />
      ) : null}
    </Card>
  );
}

// systemd's "Sat 2026-09-26 13:18:19 UTC", localised; other shapes pass through.
function formatSince(since, format) {
  const match = typeof since === "string" && since.match(/(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2}) UTC$/);
  if (!match) return since;
  const date = new Date(`${match[1]}T${match[2]}Z`);
  if (Number.isNaN(date.getTime())) return since;
  return format.dateTime(date, { dateStyle: "medium", timeStyle: "short" });
}
