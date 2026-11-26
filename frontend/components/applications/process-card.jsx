"use client";

import { cn } from "@/lib/utils";
import { useEffect, useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useFormatter, useTranslations } from "next-intl";
import { toast } from "sonner";
import { ArrowRightLeft, Loader2, Play, RotateCw, Square, Power } from "lucide-react";
import { controlApplicationProcess, getApplicationProcess } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardAction, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { formatBytes } from "@/lib/format/bytes";
import { ConvertSupervisorDialog } from "@/components/applications/convert-supervisor-dialog";
import { FACT_LABEL } from "@/lib/theme/facts";
import { firstDeployPending } from "@/lib/applications/settled";

const STATE_VARIANT = { active: "success", failed: "destructive", activating: "warning" };

const DONE_KEY = { start: "started", stop: "stopped", restart: "restarted" };

const FIRST_READ_MS = 3000;
const POLL_MS = 10000;

export function ProcessCard({ application, canManage = false, deploying = false, className }) {
  const t = useTranslations("applications.process");
  const tApp = useTranslations("applications");
  const format = useFormatter();
  const { refreshThen } = useRefresh();
  const [pending, setPending] = useState(null);
  const [confirmStop, setConfirmStop] = useState(false);
  const [converting, setConverting] = useState(false);
  const [expected, setExpected] = useState(null);

  const [live, setLive] = useState(null);
  const [base, setBase] = useState(application.process);
  if (base !== application.process) {
    setBase(application.process);
    setLive(null);
  }

  const process = live ?? application.process ?? {};
  const rawState = process.state ?? "unknown";
  const state =
    expected === "stopped" && rawState === "failed"
      ? "inactive"
      : expected === "running" && rawState !== "active"
        ? "activating"
        : (deploying || firstDeployPending(application)) && rawState === "inactive"
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
  const running = rawState === "active";

  useEffect(() => {
    if (!running || pending) return undefined;
    let active = true;
    const ctrl = new AbortController();
    async function read() {
      if (document.hidden) return;
      try {
        const next = await getApplicationProcess(application.id, { signal: ctrl.signal });
        if (active && next) setLive(next);
      } catch {
        // retry on the next poll
      }
    }
    const first = window.setTimeout(read, FIRST_READ_MS);
    const timer = window.setInterval(read, POLL_MS);
    return () => {
      active = false;
      ctrl.abort();
      window.clearTimeout(first);
      window.clearInterval(timer);
    };
  }, [running, pending, application.id]);

  const adopted = application.supervisor_mode === "pm2";
  const instances = process.instances ?? null;
  const online = process.online ?? null;

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
    { label: t("since"), value: state === "active" ? formatSince(process.since, format) : null },
    { label: t("cpu"), value: formatCpu(process.cpu_percent, format) },
    { label: t("memory"), value: memory },
    { label: t("pid"), value: process.pid != null ? String(process.pid) : "—" },
    { label: t("restarts"), value: process.restarts },
    { label: t("workers"), value: workers },
  ].filter((fact) => fact.value !== null && fact.value !== undefined && fact.value !== "");

  return (
    <Card id="process" className={cn("@container/process scroll-mt-[calc(var(--app-chrome,4rem)_+_1rem)]", className)}>
      <CardHeader className="items-center border-b">
        <CardTitle icon={Power} as="h2" className="flex flex-wrap items-center gap-2">
          {t("title")}
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
              { action: "start", icon: Play, variant: "default", reason: state === "active" ? t("alreadyRunning") : null },
              { action: "restart", icon: RotateCw, variant: "outline", reason: state === "active" ? null : t("notRunning") },
              { action: "stop", icon: Square, variant: "destructive", reason: state === "active" ? null : t("notRunning") },
            ].map(({ action, icon: Icon, variant, reason }) => (
              <Button
                key={action}
                size="sm"
                variant={variant}
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

        <dl className="grid grid-cols-2 gap-2 [&>*:first-child]:col-span-2 @xl/process:grid-cols-3 @xl/process:[&>*:first-child]:col-span-1">
          {facts.map((fact) => (
            <div key={fact.label} className="min-w-0 rounded-lg bg-muted/40 px-3 py-2 ring-1 ring-border/60">
              <dt className={FACT_LABEL}>{fact.label}</dt>
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

function formatCpu(percent, format) {
  if (typeof percent !== "number" || !Number.isFinite(percent)) return "—";
  return format.number(percent / 100, { style: "percent", maximumFractionDigits: 1 });
}

function formatSince(since, format) {
  const match = typeof since === "string" && since.match(/(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2}) UTC$/);
  if (!match) return since;
  const date = new Date(`${match[1]}T${match[2]}Z`);
  if (Number.isNaN(date.getTime())) return since;
  return format.dateTime(date, { dateStyle: "medium", timeStyle: "short" });
}
