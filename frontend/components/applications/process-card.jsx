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

  const process = application.process ?? {};
  const state = process.state ?? "unknown";
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
    { label: t("since"), value: process.since },
    { label: t("memory"), value: memory },
    { label: t("restarts"), value: process.restarts },
  ].filter((fact) => fact.value !== null && fact.value !== undefined && fact.value !== "");

  return (
    <Card className={className}>
      <CardHeader className="gap-1.5">
        <CardTitle as="h2">{t("title")}</CardTitle>
        <Badge variant={STATE_VARIANT[state] ?? "secondary"} className="font-normal">
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
              { action: "start", icon: Play },
              { action: "restart", icon: RotateCw },
              { action: "stop", icon: Square },
            ].map(({ action, icon: Icon }) => (
              <Button
                key={action}
                size="sm"
                variant="outline"
                onClick={() => run(action)}
                disabled={Boolean(pending)}
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
      </CardContent>
    </Card>
  );
}
