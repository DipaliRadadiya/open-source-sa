"use client";

import { useTranslations } from "next-intl";
import { CircleAlert, TriangleAlert } from "lucide-react";
import { cn } from "@/lib/utils";
import { PROVISION_STEPS, provisionStepLabel } from "@/lib/applications/provision-steps";
import { isRedeploying } from "@/lib/applications/settled";
import { containerState, describeWarnings, healthWarnings } from "@/lib/applications/container-state";
import { Badge } from "@/components/ui/badge";
import { HoverHint } from "@/components/ui/hover-hint";

// Kept out of applications-table.jsx so the sidebar doesn't pull DataTable into the shell bundle.
export const APPLICATION_STATUSES = ["pending", "provisioning", "active", "paused", "failed"];

export const STATUS_VARIANTS = {
  active: "success",
  failed: "destructive",
  provisioning: "warning",
  pending: "muted",
  paused: "warning",
};

// status is setup state; a stopped or crashed process must not read Running.
export function isProcessDown(application) {
  return (
    application.status === "active" &&
    Boolean(application.has_process) &&
    Boolean(application.deployed) &&
    Boolean(application.process) &&
    application.process.state !== "active" &&
    application.process.state !== "activating"
  );
}

function PillDot({ tone, live = false }) {
  return (
    <span className="relative flex size-1.5 shrink-0" aria-hidden>
      {live ? (
        <span className={cn("absolute inline-flex size-full animate-ping rounded-full opacity-60 motion-reduce:hidden", tone)} />
      ) : null}
      <span className={cn("relative inline-flex size-1.5 rounded-full", tone)} />
    </span>
  );
}

const CONTAINER_BADGES = {
  starting: { variant: "warning", key: "containerState.starting" },
  degraded: { variant: "warning", key: "containerState.degraded" },
  restarting: { variant: "destructive", key: "containerState.restarting" },
  failed: { variant: "destructive", key: "status.failed" },
};

export function ApplicationStatusBadge({ application, deploying = false }) {
  const t = useTranslations("applications");

  if (application.is_disabled) {
    return (
      <Badge variant="warning" className="gap-1.5 font-normal">
        <PillDot tone="bg-warning" />
        {t("paused")}
      </Badge>
    );
  }

  if (isRedeploying(application) || deploying) {
    return (
      <Badge variant="warning" className="gap-1.5 font-normal">
        <PillDot tone="bg-warning" live />
        {t("deploying")}
      </Badge>
    );
  }

  const container = containerState(application);
  if (CONTAINER_BADGES[container]) {
    const { variant, key } = CONTAINER_BADGES[container];
    return (
      <Badge variant={variant} className="gap-1.5 font-normal">
        <PillDot tone={DOT_TONES[variant]} live={container === "starting"} />
        {t(key)}
      </Badge>
    );
  }

  if (isProcessDown(application)) {
    return (
      <Badge variant="warning" className="gap-1.5 font-normal">
        <PillDot tone="bg-warning" />
        {t("processStoppedBadge")}
      </Badge>
    );
  }

  const variant = STATUS_VARIANTS[application.status] ?? "muted";
  return (
    <Badge variant={variant} className="gap-1.5 font-normal">
      <PillDot
        tone={DOT_TONES[variant] ?? DOT_TONES.secondary}
        live={application.status === "active" || application.status === "provisioning"}
      />
      {t(`status.${application.status}`) ?? application.status_title ?? application.status}
    </Badge>
  );
}

const DOT_TONES = {
  success: "bg-success",
  destructive: "bg-destructive",
  warning: "bg-warning",
  secondary: "bg-muted-foreground/50",
  muted: "bg-muted-foreground/50",
};

export function ApplicationStatusDot({ application, className, labelClassName }) {
  const t = useTranslations("applications");
  const paused = Boolean(application.is_disabled);
  const redeploying = isRedeploying(application);
  const container = !paused && !redeploying ? CONTAINER_BADGES[containerState(application)] : null;
  const down = !paused && !redeploying && !container && isProcessDown(application);
  const variant = paused || down ? "warning" : (STATUS_VARIANTS[application.status] ?? "secondary");
  const label = paused
    ? t("paused")
    : redeploying
      ? t("deploying")
      : container
        ? t(container.key)
        : down
          ? t("processStoppedBadge")
          : (t(`status.${application.status}`) ?? application.status_title ?? application.status);

  return (
    <span className={cn("flex min-w-0 items-center gap-1.5", className)}>
      <span className={cn("size-1.5 shrink-0 rounded-full", DOT_TONES[container?.variant ?? variant] ?? DOT_TONES.secondary)} />
      <span className={cn("truncate text-xs text-muted-foreground", labelClassName)}>{label}</span>
    </span>
  );
}

const NOTE_TAG = "flex w-fit max-w-full min-w-0 items-start gap-1 whitespace-normal [overflow-wrap:anywhere] rounded-md px-1.5 py-0.5 text-xs leading-4";

export function ApplicationStatusNotes({ application, className }) {
  const t = useTranslations("applications");
  const processDown = isProcessDown(application) && application.process.state === "failed";
  const isGit = Boolean(application.repository || application.repository_url);
  const deployFailed = isGit && application.status === "active" && Boolean(application.failed_step);
  const provisioning =
    (application.status === "pending" || application.status === "provisioning") && application.steps?.length;
  const container = containerState(application);
  const reference = application.status === "failed" && application.reference && container !== "restarting";
  const warnings = healthWarnings(application);
  const helperNote = (container === "degraded" || container === "starting") && warnings.length > 0;
  const mainNote = (container === "failed" || container === "restarting") && warnings.length > 0;
  if (!provisioning && !reference && !processDown && !deployFailed && !helperNote && !mainNote) return null;
  return (
    <div className={cn("space-y-1", className)}>
      {provisioning ? (
        <p className="max-w-40 text-xs text-pretty text-muted-foreground">
          {provisionStepLabel(application.steps.at(-1), t, "details.")}
        </p>
      ) : null}
      {reference ? (
        application.last_failure?.message ? (
          <p className="max-w-52 text-xs text-pretty text-destructive">
            {application.last_failure.message}
          </p>
        ) : application.failed_reason_title ? (
          <HoverHint content={application.failed_reason_title}>
            <p className="line-clamp-2 max-w-52 text-xs text-pretty text-destructive">
              {application.failed_reason_title}
            </p>
          </HoverHint>
        ) : PROVISION_STEPS.has(application.failed_step) ? (
          <p className="max-w-52 text-xs text-pretty text-destructive">
            {t("details.failedAt", {
              step: provisionStepLabel(application.failed_step, t, "details."),
            })}
          </p>
        ) : (
          <p className="max-w-52 truncate font-mono text-xs text-destructive">
            {application.reference}
          </p>
        )
      ) : null}
      {processDown ? (
        <p className={cn(NOTE_TAG, "bg-destructive-soft text-destructive")}>
          <CircleAlert className="mt-0.5 size-3 shrink-0" />
          {t("markers.processFailed")}
        </p>
      ) : null}
      {deployFailed ? (
        <p className={cn(NOTE_TAG, "bg-warning-soft text-[color-mix(in_oklch,var(--warning)_75%,var(--foreground))] dark:text-warning")}>
          <TriangleAlert className="mt-0.5 size-3 shrink-0" />
          {t("tiles.deployFailed")}
        </p>
      ) : null}
      {mainNote ? (
        <p className={cn(NOTE_TAG, "bg-destructive-soft text-destructive")}>
          <CircleAlert className="mt-0.5 size-3 shrink-0" />
          <span>
            {t("markers.mainUnhealthy", {
              service: warnings[0].service,
              state: t.has(`helperState.${warnings[0].state}`) ? t(`helperState.${warnings[0].state}`) : warnings[0].state,
            })}
            {warnings.length > 1 ? <> · {t("markers.alsoAffected", { services: describeWarnings(warnings.slice(1), t) })}</> : null}
          </span>
        </p>
      ) : null}
      {helperNote ? (
        <p className={cn(NOTE_TAG, "bg-warning-soft text-[color-mix(in_oklch,var(--warning)_75%,var(--foreground))] dark:text-warning")}>
          <TriangleAlert className="mt-0.5 size-3 shrink-0" />
          {t("markers.helperUnhealthy", { count: warnings.length, services: describeWarnings(warnings, t) })}
        </p>
      ) : null}
    </div>
  );
}
