"use client";

import { useTranslations } from "next-intl";
import { CircleAlert, TriangleAlert } from "lucide-react";
import { cn } from "@/lib/utils";
import { PROVISION_STEPS, provisionStepLabel } from "@/lib/applications/provision-steps";
import { isRedeploying } from "@/lib/applications/settled";
import { containerState } from "@/lib/applications/container-state";
import { Badge } from "@/components/ui/badge";

/* Kept out of `applications-table.jsx` so the sidebar does not pull DataTable into the shell bundle. */
// The statuses `filter[status]` accepts, in API order. Fixed rather than derived
// from the current page's rows, which would only offer statuses on that page.
// `paused` is a filter value, not a stored status: a paused site stays `active` (is_disabled).
export const APPLICATION_STATUSES = ["pending", "provisioning", "active", "paused", "failed"];

export const STATUS_VARIANTS = {
  active: "success",
  failed: "destructive",
  provisioning: "warning",
  pending: "muted",
  // Same amber as the Paused badge.
  paused: "warning",
};

// `status` is setup state ("active" = set up), not whether the app runs. An app with a
// process (Node, git) that is stopped or crashed must not read "Running".
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

// Container states that differ from `status`; `running` reads the same as `active`.
const CONTAINER_BADGES = {
  starting: { variant: "warning", key: "containerState.starting" },
  restarting: { variant: "destructive", key: "containerState.restarting" },
  failed: { variant: "destructive", key: "status.failed" },
};

// Badge and notes are split because the card and table place them differently.
export function ApplicationStatusBadge({ application }) {
  const t = useTranslations("applications");

  // Paused outranks status: `disable()` serves a placeholder page but `status`
  // stays `active`. Amber, not destructive: deliberate, but worth noticing.
  if (application.is_disabled) {
    return (
      <Badge variant="warning" className="font-normal">
        {t("paused")}
      </Badge>
    );
  }

  // The API reports `provisioning` for a redeploy too, while the site keeps
  // serving its old code.
  if (isRedeploying(application)) {
    return (
      <Badge variant="warning" className="font-normal">
        {t("deploying")}
      </Badge>
    );
  }

  // An `active` container site can still be down: a failed PUT /container or
  // Pull leaves `status` alone and records the failure beside it.
  const container = containerState(application);
  if (CONTAINER_BADGES[container]) {
    const { variant, key } = CONTAINER_BADGES[container];
    return (
      <Badge variant={variant} className="font-normal">
        {t(key)}
      </Badge>
    );
  }

  if (isProcessDown(application)) {
    return (
      <Badge variant="warning" className="font-normal">
        {t("processStoppedBadge")}
      </Badge>
    );
  }

  return (
    <Badge
      variant={STATUS_VARIANTS[application.status] ?? "muted"}
      className="font-normal"
    >
      {t(`status.${application.status}`) ?? application.status_title ?? application.status}
    </Badge>
  );
}

// One tone per badge variant, so the dot cannot drift from STATUS_VARIANTS.
const DOT_TONES = {
  success: "bg-success",
  destructive: "bg-destructive",
  warning: "bg-warning",
  secondary: "bg-muted-foreground/50",
};

// Sidebar variant; shares `STATUS_VARIANTS` and the `is_disabled` precedence above.
export function ApplicationStatusDot({ application, className }) {
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
      <span className="truncate text-xs text-muted-foreground">{label}</span>
    </span>
  );
}

export function ApplicationStatusNotes({ application, className }) {
  const t = useTranslations("applications");
  // The badge already says "Stopped"; the note adds only that it crashed.
  const processDown = isProcessDown(application) && application.process.state === "failed";
  // Deploy is git-only, so this marker is too.
  const isGit = Boolean(application.repository || application.repository_url);
  const deployFailed = isGit && application.status === "active" && Boolean(application.failed_step);
  const provisioning =
    (application.status === "pending" || application.status === "provisioning") && application.steps?.length;
  const reference = application.status === "failed" && application.reference;
  if (!provisioning && !reference && !processDown && !deployFailed) return null;
  return (
    <div className={cn("space-y-1", className)}>
      {/* The API sends raw step identifiers; show labels. Wraps rather than
          truncates so a failure reason is never cut off. */}
      {provisioning ? (
        <p className="max-w-40 text-xs text-pretty text-muted-foreground">
          {provisionStepLabel(application.steps.at(-1), t, "details.")}
        </p>
      ) : null}
      {/* Shows where it stopped rather than the bare support id; falls back to the
          reference when the API sends no failed_step. */}
      {reference ? (
        // Prefer the server's localized cause. Show the step only when `provisionStepLabel`
        // can name it; its fallback reads as nonsense here.
        application.failed_reason_title ? (
          /* Wrapped: a truncated sentence loses its meaning. */
          <p className="max-w-52 text-xs text-pretty text-destructive">
            {application.failed_reason_title}
          </p>
        ) : PROVISION_STEPS.has(application.failed_step) ? (
          <p className="max-w-52 text-xs text-pretty text-destructive">
            {/* Same string as the detail page: both describe one failure. */}
            {t("details.failedAt", {
              step: provisionStepLabel(application.failed_step, t, "details."),
            })}
          </p>
        ) : (
          /* Truncated, not wrapped: the column is fixed-width and a partial UUID is still
             recognisable; the detail page shows it in full. */
          <p className="max-w-52 truncate font-mono text-xs text-destructive">
            {application.reference}
          </p>
        )
      ) : null}
      {processDown ? (
        <p className="flex items-center gap-1 text-xs text-destructive">
          <CircleAlert className="size-3 shrink-0" />
          {t("markers.processFailed")}
        </p>
      ) : null}
      {deployFailed ? (
        <p className="flex items-center gap-1 text-xs text-warning">
          <TriangleAlert className="size-3 shrink-0" />
          {t("markers.deployFailed")}
        </p>
      ) : null}
    </div>
  );
}
