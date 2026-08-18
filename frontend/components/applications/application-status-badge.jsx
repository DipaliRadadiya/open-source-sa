"use client";

import { useTranslations } from "next-intl";
import { CircleAlert, TriangleAlert } from "lucide-react";
import { cn } from "@/lib/utils";
import { provisionStepLabel } from "@/lib/applications/provision-steps";
import { Badge } from "@/components/ui/badge";

/**
 * How an application's state is said, in one place.
 *
 * Its own module rather than living beside the table: the sidebar's application
 * card shows this badge on every page of the app shell, and importing it from
 * `applications-table.jsx` would pull the DataTable, the row actions and the
 * card list into the shell bundle along with it.
 *
 * Before this existed there were three copies of the mapping — the table, the
 * detail page header and the sidebar — and they disagreed on screen: the same
 * paused site read green "Running" in the header and red "Running" in the
 * sidebar.
 */
export const STATUS_VARIANTS = {
  active: "success",
  failed: "destructive",
  provisioning: "warning",
  pending: "secondary",
};

// A site can be "active" and still be in trouble: its process may have died, or
// its last deploy may have failed while the old code keeps serving. `status`
// alone reads green in both cases, so a list would otherwise hide the two
// things a user most needs to catch at a glance.
/**
 * Status is split in two because the card and the table place the parts
 * differently — the card puts the badge inline on its facts line and the notes
 * underneath — but both sides still read from one definition, so the
 * provisioning step, the failure reference and the process/deploy markers
 * cannot drift apart.
 */
export function ApplicationStatusBadge({ application }) {
  const t = useTranslations("applications");

  // Paused outranks the status word. `disable()` swaps the vhost for a
  // placeholder page, so visitors are being turned away — but `status` stays
  // `active`, because a healthy site can be paused deliberately. Reading only
  // `status` printed a green "Running" over a site that served nobody.
  //
  // Amber, not destructive: nothing is broken and someone chose this. It should
  // still be noticed, which is why it is not the calm grey of `secondary`.
  if (application.is_disabled) {
    return (
      <Badge variant="warning" className="font-normal">
        {t("paused")}
      </Badge>
    );
  }

  return (
    <Badge
      variant={STATUS_VARIANTS[application.status] ?? "secondary"}
      className="font-normal"
    >
      {application.status_title ?? application.status}
    </Badge>
  );
}

export function ApplicationStatusNotes({ application, className }) {
  const t = useTranslations("applications");
  const processDown =
    application.status === "active" &&
    application.has_process &&
    application.deployed &&
    application.process &&
    application.process.state !== "active" &&
    application.process.state !== "activating";
  // Deploy is git-only — a one-click install has nothing to pull — so this
  // marker is too, even if a non-git app somehow carried a failed_step.
  const isGit = Boolean(application.repository || application.repository_url);
  const deployFailed = isGit && application.status === "active" && Boolean(application.failed_step);
  const provisioning =
    (application.status === "pending" || application.status === "provisioning") && application.steps?.length;
  const reference = application.status === "failed" && application.reference;
  if (!provisioning && !reference && !processDown && !deployFailed) return null;
  return (
    <div className={cn("space-y-1", className)}>
      {/* The API sends raw step identifiers (`create_php_pool`), which were
          being printed straight into the row. */}
      {provisioning ? (
        <p className="max-w-40 truncate text-xs text-muted-foreground">
          {provisionStepLabel(application.steps.at(-1), t, "details.")}
        </p>
      ) : null}
      {reference ? <p className="font-mono text-xs text-destructive">{application.reference}</p> : null}
      {processDown ? (
        <p className="flex items-center gap-1 text-xs text-destructive">
          <CircleAlert className="size-3 shrink-0" />
          {application.process.state === "failed" ? t("markers.processFailed") : t("markers.processStopped")}
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
