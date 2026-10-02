"use client";

import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { CircleAlert, Loader2, RotateCw } from "lucide-react";
import { toast } from "sonner";
import { cn } from "@/lib/utils";
import { retryProvisioning } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { provisionStepLabel } from "@/lib/applications/provision-steps";
import { useRefresh } from "@/hooks/use-refresh";
import { Button } from "@/components/ui/button";
import { CopyButton } from "@/components/ui/copy-button";
import { Progress } from "@/components/ui/progress";
import { Card, CardContent } from "@/components/ui/card";
import { StepList } from "@/components/applications/step-list";

const POLL_MS = 4000;

/** Give up after 20 minutes, matching the clone screen; a job that has not
 *  moved by then is stuck. */
const POLL_LIMIT_MS = 20 * 60 * 1000;

// The bar is indeterminate: provisioning reports only finished steps, and the
// steps depend on the site type, so there is no total.
export function ProvisioningCard({ application, canManage = false }) {
  const t = useTranslations("applications.details");
  const { refresh, refreshThen, pending: refreshing } = useRefresh();
  const router = useRouter();
  const [retrying, setRetrying] = useState(false);
  const [stalled, setStalled] = useState(false);
  const timer = useRef(null);

  const working = application.status === "pending" || application.status === "provisioning";
  const failed = application.status === "failed";
  const steps = application.steps ?? [];

  // Unknown step ids get a generic phrase rather than the raw identifier.
  const stepLabel = (step) => provisionStepLabel(step, t);

  useEffect(() => {
    if (!working) return undefined;
    timer.current = window.setInterval(() => router.refresh(), POLL_MS);
    const stop = window.setTimeout(() => {
      window.clearInterval(timer.current);
      setStalled(true);
    }, POLL_LIMIT_MS);

    return () => {
      window.clearInterval(timer.current);
      window.clearTimeout(stop);
    };
  }, [router, working]);

  async function retry() {
    setRetrying(true);
    try {
      await retryProvisioning(application.id);
      // Busy until the page shows the new run.
      refreshThen(() => setRetrying(false));
    } catch (error) {
      toast.error(
        // The retry itself failed; "Stopped at…" would read as the old failure repeating.
        apiMessage(error, t("retryFailed")),
      );
      setRetrying(false);
    }
  }

  const headline = failed
    ? t("failedTitle")
    : stalled
      ? t("stalledTitle")
      : t("settingUp");

  const body = failed
    // The server's localized reason when it has one (usually null); otherwise the
    // step it stopped at.
    ? (application.failed_reason_title ??
       t("failedAt", { step: stepLabel(application.failed_step) }))
    : stalled
      ? t("stalledBody")
      : application.status === "provisioning"
        ? t("keepWaiting")
        : t("pending");

  return (
    <Card
      className={cn(
        "gap-0 overflow-hidden py-0 shadow-sm",
        failed && "border-destructive/30",
      )}
    >
      <div className="flex items-start gap-3 border-b px-5 py-4">
        <span
          className={cn(
            "flex size-11 shrink-0 items-center justify-center rounded-xl",
            failed || stalled ? "bg-destructive/10" : "bg-primary/10",
          )}
        >
          {failed || stalled ? (
            <CircleAlert className="size-6 text-destructive" aria-hidden />
          ) : (
            <Loader2 className="size-6 animate-spin text-primary" aria-hidden />
          )}
        </span>
        <div className="min-w-0 flex-1 space-y-1">
          <p className="font-medium">{headline}</p>
          <p className="text-sm text-muted-foreground">{body}</p>
        </div>
      </div>

      <CardContent className="space-y-4 px-5 py-4">
        {working && !stalled ? <Progress indeterminate className="h-1.5" /> : null}

        {failed && application.reference ? (
          // The reference is copyable: it is what support needs.
          <div className="flex items-center gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">
            <CircleAlert className="size-4 shrink-0" />
            <span className="min-w-0 flex-1">
              {t("reference", { reference: application.reference })}
            </span>
            <CopyButton value={application.reference} label={t("copyReference")} />
          </div>
        ) : null}

        {/* An unnamed working row: the API reports what finished, never what started. */}
        <StepList
          steps={steps}
          working={working && !stalled}
          workingLabel={steps.length ? t("working") : t("starting")}
          failedStep={failed ? application.failed_step : null}
          label={stepLabel}
        />

        {/* Elapsed time is the API's phrase from `provisioning_started_at`, so a
            skewed browser clock cannot invent a duration. */}
        {working && !stalled ? (
          <p className="border-t pt-3 text-xs text-muted-foreground">
            {application.provisioning_started_at_human
              ? t("startedAgo", { ago: application.provisioning_started_at_human })
              : t("takesMinutes")}
          </p>
        ) : null}

        {stalled ? (
          <div className="border-t pt-4">
            <Button
              variant="outline"
              onClick={refresh}
              disabled={refreshing}
              className="w-full sm:w-auto"
            >
              <RotateCw className={cn("size-4", refreshing && "animate-spin")} />
              {t("checkAgain")}
            </Button>
          </div>
        ) : null}

        {failed && canManage ? (
          <div className="flex justify-end border-t pt-4">
            <Button onClick={retry} disabled={retrying}>
              {retrying ? (
                <Loader2 className="size-4 animate-spin" />
              ) : (
                <RotateCw className="size-4" />
              )}
              {retrying ? t("retrying") : t("retry")}
            </Button>
          </div>
        ) : null}
      </CardContent>
    </Card>
  );
}
