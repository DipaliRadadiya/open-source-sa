"use client";

import { useTranslations } from "next-intl";
import { Loader2, CircleCheck, CircleX, RotateCcw, WifiOff } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Progress } from "@/components/ui/progress";

// Renders one run: a live progress bar while it works, then a success or
// failure card. The parent owns polling and passes `reconnecting` when the
// panel is mid-restart (503 / refused) — which is normal progress, not an error.
export function UpdateProgress({ run, reconnecting = false, slow = false, dryRun = false, onFinish }) {
  const t = useTranslations("panelUpdate");
  const pct = run.total_steps > 0 ? Math.round((run.step_number / run.total_steps) * 100) : 0;

  if (run.status === "succeeded") {
    return (
      <div className="flex flex-col items-start gap-3 rounded-2xl border border-success/30 bg-success/5 p-5">
        <div className="flex items-center gap-3">
          <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-success/10">
            <CircleCheck className="size-6 text-success" aria-hidden />
          </span>
          <div>
            <p className="font-medium">
              {dryRun
                ? t("dryRunDone")
                : run.to_version
                  ? t("succeeded", { version: run.to_version })
                  : t("succeededNoVersion")}
            </p>
            <p className="text-sm text-muted-foreground">
              {dryRun ? t("dryRunDoneBody") : t("succeededBody")}
            </p>
          </div>
        </div>
        <Button onClick={onFinish} className="ml-14">
          <RotateCcw className="size-4" />
          {dryRun ? t("dismiss") : t("reload")}
        </Button>
      </div>
    );
  }

  if (run.status === "failed") {
    return (
      <div className="flex flex-col items-start gap-3 rounded-2xl border border-destructive/30 bg-destructive/5 p-5">
        <div className="flex items-start gap-3">
          <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-destructive/10">
            <CircleX className="size-6 text-destructive" aria-hidden />
          </span>
          <div className="min-w-0 space-y-1">
            <p className="font-medium">{t("failed")}</p>
            {run.reason_title ? (
              <p className="text-sm text-muted-foreground">{run.reason_title}</p>
            ) : null}
            <p className="text-sm">
              {run.rolled_back ? t("rolledBack") : t("notRolledBack")}
            </p>
            {run.reference ? (
              <p className="pt-1 font-mono text-xs text-muted-foreground">
                {t("reference", { reference: run.reference })}
              </p>
            ) : null}
          </div>
        </div>
        <Button onClick={onFinish} variant="outline" className="ml-14">
          <RotateCcw className="size-4" />
          {t("dismiss")}
        </Button>
      </div>
    );
  }

  // Running / pending.
  return (
    <div className="space-y-3 rounded-2xl border bg-card p-5">
      <div className="flex items-center justify-between text-sm">
        <span className="flex items-center gap-2 font-medium">
          <Loader2 className="size-4 animate-spin text-primary" />
          {run.current_step_title || run.status_title || t("working")}
        </span>
        {run.total_steps > 0 ? (
          <span className="text-muted-foreground">
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
    </div>
  );
}
