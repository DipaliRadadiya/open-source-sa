"use client";

import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { Check, CircleAlert, Loader2, RotateCw } from "lucide-react";
import { toast } from "sonner";
import { cn } from "@/lib/utils";
import { retryProvisioning } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { provisionStepLabel } from "@/lib/applications/provision-steps";
import { Button } from "@/components/ui/button";
import { CopyButton } from "@/components/ui/copy-button";
import { Progress } from "@/components/ui/progress";
import { Card, CardContent } from "@/components/ui/card";

const POLL_MS = 4000;

/** Give up after 20 minutes, matching the clone screen — a job that has not
 *  moved by then is stuck, and polling a dead queue forever helps nobody. */
const POLL_LIMIT_MS = 20 * 60 * 1000;

/**
 * The whole page while a site is being built — creating an application
 * redirects straight here, so this is the first thing anyone sees after
 * clicking Create.
 *
 * Deliberately built in the same visual language as the clone progress screen:
 * icon tile, bar, ringed step rows, reassurance on a footer rule. Two screens
 * that answer "is my thing being built?" should not look like different
 * products.
 *
 * The bar is indeterminate, unlike the clone's. A clone reports `step_number`
 * and `total_steps`; provisioning reports only the steps that have *finished*,
 * and which ones run at all depends on the site type — so there is no
 * denominator, and a creeping percentage would be invented.
 */
export function ProvisioningCard({ application, canManage = false }) {
  const t = useTranslations("applications.details");
  const router = useRouter();
  const [retrying, setRetrying] = useState(false);
  const [stalled, setStalled] = useState(false);
  const timer = useRef(null);

  const working = application.status === "pending" || application.status === "provisioning";
  const failed = application.status === "failed";
  const steps = application.steps ?? [];

  // Raw identifiers are keys, not copy — anything unrecognised gets a generic
  // phrase so a new backend step never surfaces as `install_cache`.
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
      router.refresh();
    } catch (error) {
      toast.error(
        apiMessage(error, t("failedAt", { step: stepLabel(application.failed_step) })),
      );
    } finally {
      setRetrying(false);
    }
  }

  const headline = failed
    ? t("failedTitle")
    : stalled
      ? t("stalledTitle")
      : t("settingUp");

  const body = failed
    // The server's own reason when it identified one — already localized, and
    // it names the cause rather than the step the cause happened to stop at.
    // Null for most failures by design, so the step remains the fallback.
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
          // The reference is the only thing support can act on, so it is
          // copyable rather than something to transcribe off the screen.
          <div className="flex items-center gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">
            <CircleAlert className="size-4 shrink-0" />
            <span className="min-w-0 flex-1">
              {t("reference", { reference: application.reference })}
            </span>
            <CopyButton value={application.reference} label={t("copyReference")} />
          </div>
        ) : null}

        <ol className="space-y-2.5">
          {steps.map((step, index) => (
            <li key={`${step}-${index}`} className="flex items-center gap-3 text-sm">
              <span className="flex size-5 shrink-0 items-center justify-center rounded-full border border-success bg-success/10 text-success">
                <Check className="size-3" />
              </span>
              <span className="text-muted-foreground">{stepLabel(step)}</span>
            </li>
          ))}

          {/* An unnamed row rather than the next step: the API reports what
              finished, never what started. */}
          {working && !stalled ? (
            <li className="flex items-center gap-3 text-sm">
              <span className="flex size-5 shrink-0 items-center justify-center rounded-full border border-primary bg-primary/10 text-primary">
                <Loader2 className="size-3 animate-spin" />
              </span>
              <span className="font-medium">
                {steps.length ? t("working") : t("starting")}
              </span>
            </li>
          ) : null}

          {failed ? (
            <li className="flex items-center gap-3 text-sm">
              <span className="flex size-5 shrink-0 items-center justify-center rounded-full border border-destructive bg-destructive/10 text-destructive">
                <CircleAlert className="size-3" />
              </span>
              <span className="font-medium text-destructive">
                {stepLabel(application.failed_step)}
              </span>
            </li>
          ) : null}
        </ol>

        {/* No percentage and no elapsed timer: the step count varies by site
            type, and the only timestamp available is created_at, which is
            stale after a retry. A duration people can plan around beats a
            number that is wrong. */}
        {working && !stalled ? (
          <p className="border-t pt-3 text-xs text-muted-foreground">{t("takesMinutes")}</p>
        ) : null}

        {stalled ? (
          <div className="border-t pt-4">
            <Button variant="outline" onClick={() => router.refresh()} className="w-full sm:w-auto">
              <RotateCw className="size-4" />
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
