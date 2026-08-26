"use client";

import { useEffect, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import {
  Loader2,
  CircleCheck,
  CircleX,
  RotateCcw,
  WifiOff,
  SquareTerminal,
  ChevronDown,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Progress } from "@/components/ui/progress";
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@/components/ui/collapsible";

// Renders one run: a live progress bar while it works, then a success or
// failure card. The parent owns polling and passes `reconnecting` when the
// panel is mid-restart (503 / refused) — which is normal progress, not an error.
//
// All three states are the same card the resting page uses: a chip-and-headline
// band, and where there is an action, its own footer strip. The button used to
// hang off an `ml-14` that guessed the chip's width.
function UpdateOutput({ run, defaultOpen = false }) {
  const t = useTranslations("panelUpdate");

  if (!run.output) return null;

  return (
    <Collapsible defaultOpen={defaultOpen} className="group/output pt-2">
      <CollapsibleTrigger asChild>
        <Button variant="ghost" size="sm" className="-ml-2 h-8 gap-2 px-2">
          <SquareTerminal className="size-4" />
          {t("outputTitle")}
          <ChevronDown className="size-4 transition-transform group-data-[state=open]/output:rotate-180" />
        </Button>
      </CollapsibleTrigger>
      <CollapsibleContent className="pt-2">
        {run.output_truncated ? (
          <p className="mb-1 text-xs text-muted-foreground">{t("outputTruncated")}</p>
        ) : null}
        <pre className="max-h-80 overflow-auto rounded-md border bg-zinc-950 p-3 font-mono text-xs leading-relaxed whitespace-pre-wrap text-zinc-100">
          {run.output}
        </pre>
      </CollapsibleContent>
    </Collapsible>
  );
}

function Outcome({ chip, tint, Icon, title, children, action }) {
  return (
    <Card className="gap-0 overflow-hidden py-0 shadow-sm">
      <div className="flex items-start gap-4 border-b px-5 py-4">
        <span className={cn("flex size-11 shrink-0 items-center justify-center rounded-xl", chip)}>
          <Icon className={cn("size-6", tint)} aria-hidden />
        </span>
        <div className="min-w-0 flex-1 space-y-1">
          <h2 className="font-heading text-base leading-snug font-semibold tracking-tight">
            {title}
          </h2>
          {children}
        </div>
      </div>
      <div className="flex flex-wrap justify-end gap-2 border-t bg-muted/20 px-5 py-3">{action}</div>
    </Card>
  );
}

export function UpdateProgress({ run, reconnecting = false, slow = false, dryRun = false, onFinish }) {
  const t = useTranslations("panelUpdate");
  const [reloadIn, setReloadIn] = useState(15);
  const onFinishRef = useRef(onFinish);
  const pct = run.total_steps > 0 ? Math.round((run.step_number / run.total_steps) * 100) : 0;

  useEffect(() => {
    onFinishRef.current = onFinish;
  }, [onFinish]);

  useEffect(() => {
    if (run.status !== "succeeded" || dryRun) return undefined;

    const interval = window.setInterval(() => {
      setReloadIn((seconds) => Math.max(0, seconds - 1));
    }, 1000);
    const timeout = window.setTimeout(() => onFinishRef.current(), 15000);

    return () => {
      window.clearInterval(interval);
      window.clearTimeout(timeout);
    };
  }, [run.id, run.status, dryRun]);

  if (run.status === "succeeded") {
    return (
      <Outcome
        chip="bg-success/10"
        tint="text-success"
        Icon={CircleCheck}
        title={
          dryRun
            ? t("dryRunDone")
            : run.to_version
              ? t("succeeded", { version: run.to_version })
              : t("succeededNoVersion")
        }
        action={
          <Button onClick={onFinish}>
            <RotateCcw className="size-4" />
            {dryRun ? t("dismiss") : t("reloadCountdown", { seconds: reloadIn })}
          </Button>
        }
      >
        <p className="text-sm text-muted-foreground">
          {dryRun ? t("dryRunDoneBody") : t("succeededBody")}
        </p>
        <UpdateOutput run={run} />
      </Outcome>
    );
  }

  if (run.status === "failed") {
    return (
      <Outcome
        chip="bg-destructive/10"
        tint="text-destructive"
        Icon={CircleX}
        title={t("failed")}
        action={
          <Button onClick={onFinish} variant="outline">
            <RotateCcw className="size-4" />
            {t("dismiss")}
          </Button>
        }
      >
        {run.reason_title ? (
          <p className="text-sm text-muted-foreground">{run.reason_title}</p>
        ) : null}
        {/* Whether the panel is still the thing you were using a minute ago is
            the only question being asked here, so it is not muted. */}
        <p className="text-sm">
          {run.reason === "target_not_newer"
            ? t("notChanged")
            : run.rolled_back
              ? t("rolledBack")
              : t("notRolledBack")}
        </p>
        {run.reference ? (
          <p className="pt-1 font-mono text-xs break-words text-muted-foreground">
            {t("reference", { reference: run.reference })}
          </p>
        ) : null}
        <UpdateOutput run={run} defaultOpen />
      </Outcome>
    );
  }

  // Running / pending.
  return (
    <Card className="gap-0 overflow-hidden py-0 shadow-sm">
      <CardContent className="space-y-3 px-5 py-4">
        <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-sm">
          <span className="flex min-w-0 items-center gap-2 font-medium">
            <Loader2 className="size-4 shrink-0 animate-spin text-primary" />
            {run.current_step_title || run.status_title || t("working")}
          </span>
          {run.total_steps > 0 ? (
            <span className="tabular-nums text-muted-foreground">
              {t("stepCount", { step: run.step_number, total: run.total_steps })}
            </span>
          ) : null}
        </div>
        <Progress value={pct} className="h-2.5" />
        <p
          className={cn(
            "flex items-start gap-2 text-xs",
            reconnecting ? "text-warning" : "text-muted-foreground",
          )}
        >
          {reconnecting ? <WifiOff className="mt-0.5 size-3 shrink-0" /> : null}
          <span>{reconnecting ? t("reconnecting") : slow ? t("slow") : t("downtimeNote")}</span>
        </p>
        {/* The panel goes dark for minutes — reassure that walking away is fine. */}
        <p className="text-xs text-muted-foreground">{t("safeToLeave")}</p>
        <UpdateOutput run={run} />
      </CardContent>
    </Card>
  );
}
