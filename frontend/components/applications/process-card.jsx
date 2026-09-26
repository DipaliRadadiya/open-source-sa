"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useFormatter, useTranslations } from "next-intl";
import { toast } from "sonner";
import { Loader2, Play, RotateCw, Square } from "lucide-react";
import { controlApplicationProcess } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { formatBytes } from "@/lib/format/bytes";

const STATE_VARIANT = { active: "success", failed: "destructive", activating: "warning" };

/**
 * Spelled out rather than built as `${action}ed`, which produced `stoped` for
 * stop and printed the raw key path in the toast. Only stop was affected, so it
 * survived every reading of the code; a key assembled from a template literal is
 * also invisible to grep, so nothing flagged it as missing.
 */
const DONE_KEY = { start: "started", stop: "stopped", restart: "restarted" };

/**
 * Only for sites that run their own process (`has_process` — true exactly when
 * a start command is set). PHP and static sites have nothing to supervise.
 *
 * A freshly created git site is `active` with a process that has never started,
 * because the code has not arrived yet. That reads as "deploy to start", not as
 * a fault, so it is not painted red.
 */
export function ProcessCard({ application, canManage = false, className }) {
  const t = useTranslations("applications.process");
  const tApp = useTranslations("applications");
  const format = useFormatter();
  const router = useRouter();
  const [pending, setPending] = useState(null);
  const [confirmStop, setConfirmStop] = useState(false);
  // systemd records a stopped Node process as "failed" (it exits on SIGTERM),
  // so the card called the Stop someone just pressed a crash. Remembered for
  // this visit only; the server's own answer is still the one after a reload.
  const [stoppedHere, setStoppedHere] = useState(false);

  const process = application.process ?? {};
  const rawState = process.state ?? "unknown";
  const state = stoppedHere && rawState === "failed" ? "inactive" : rawState;
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

  async function run(action) {
    setPending(action);
    try {
      await controlApplicationProcess(application.id, action);
      setStoppedHere(action === "stop");
      setConfirmStop(false);
      toast.success(t(DONE_KEY[action]));
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("failed")));
    } finally {
      setPending(null);
    }
  }

  const facts = [
    { label: t("state"), value: stateLabel },
    // `since` is when the process last started; on a stopped one it read as
    // "Running since" a time it no longer is.
    { label: t("since"), value: state === "active" ? formatSince(process.since, format) : null },
    { label: t("memory"), value: memory },
    { label: t("restarts"), value: process.restarts },
  ].filter((fact) => fact.value !== null && fact.value !== undefined && fact.value !== "");

  return (
    <Card className={className}>
      <CardHeader className="gap-1.5">
        <CardTitle as="h2">{t("title")}</CardTitle>
        <Badge variant={STATE_VARIANT[state] ?? "muted"} className="font-normal">
          {stateLabel}
        </Badge>
      </CardHeader>
      <CardContent className="space-y-4">
        {notStartedYet ? (
          <p className="text-sm text-muted-foreground">{t("deployToStart")}</p>
        ) : null}

        <div className="grid gap-4 text-sm sm:grid-cols-2">
          {facts.map((fact) => (
            <div key={fact.label} className="space-y-1">
              <p className="text-xs text-muted-foreground">{fact.label}</p>
              <p className="font-mono text-xs">{fact.value}</p>
            </div>
          ))}
        </div>

        {canManage ? (
          <div className="flex flex-wrap gap-2">
            {[
              // Start only when it is not running; Restart and Stop only when
              // it is — each disabled one says why.
              { action: "start", icon: Play, reason: state === "active" ? t("alreadyRunning") : null },
              { action: "restart", icon: RotateCw, reason: state === "active" ? null : t("notRunning") },
              { action: "stop", icon: Square, reason: state === "active" ? null : t("notRunning") },
            ].map(({ action, icon: Icon, reason }) => (
              <Button
                key={action}
                size="sm"
                variant="outline"
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
          </div>
        ) : null}
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
    </Card>
  );
}

/**
 * systemd's "Sat 2026-09-26 13:18:19 UTC", in the reader's language. Left as
 * it came when it is not that shape.
 */
function formatSince(since, format) {
  const match = typeof since === "string" && since.match(/(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2}) UTC$/);
  if (!match) return since;
  const date = new Date(`${match[1]}T${match[2]}Z`);
  if (Number.isNaN(date.getTime())) return since;
  return format.dateTime(date, { dateStyle: "medium", timeStyle: "short" });
}
