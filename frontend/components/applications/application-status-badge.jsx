"use client";

import { useTranslations } from "next-intl";
import { CircleAlert, TriangleAlert } from "lucide-react";
import { cn } from "@/lib/utils";
import { PROVISION_STEPS, provisionStepLabel } from "@/lib/applications/provision-steps";
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
// The statuses `filter[status]` accepts, in the order the API lists them.
// Named here rather than derived from the rows on screen: the list pages at
// ten, so derived options could only ever offer the statuses that happen to be
// on the current page — and filtering to "failed" would stop being possible
// the moment the failed site fell off page one.
export const APPLICATION_STATUSES = ["pending", "provisioning", "active", "failed"];

export const STATUS_VARIANTS = {
  active: "success",
  failed: "destructive",
  provisioning: "warning",
  pending: "muted",
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
      variant={STATUS_VARIANTS[application.status] ?? "muted"}
      className="font-normal"
    >
      {t(`status.${application.status}`) ?? application.status_title ?? application.status}
    </Badge>
  );
}

// The same four states as a dot rather than a filled pill. One tone per badge
// variant, so this cannot drift from STATUS_VARIANTS above.
const DOT_TONES = {
  success: "bg-success",
  destructive: "bg-destructive",
  warning: "bg-warning",
  secondary: "bg-muted-foreground/50",
};

/**
 * Status as a dot and a word, for the sidebar's application card.
 *
 * Deliberately in THIS file, reusing `STATUS_VARIANTS` and the `is_disabled`
 * precedence above rather than restating either. The header and the sidebar
 * once held their own copies of that mapping and disagreed on screen — the
 * same paused site read green "Running" in one and red "Running" in the other
 * — which is the whole reason this module exists.
 *
 * A dot instead of a badge because the sidebar is a column of quiet nav items:
 * a filled green pill was the loudest thing in it, for a fact that is true
 * almost always. The pill stays where a status is the subject — the list, the
 * page header — and this keeps the loud treatment available for a site that is
 * actually in trouble, which still turns the dot red or amber.
 */
export function ApplicationStatusDot({ application, className }) {
  const t = useTranslations("applications");
  const paused = Boolean(application.is_disabled);
  const variant = paused ? "warning" : (STATUS_VARIANTS[application.status] ?? "secondary");
  const label = paused
    ? t("paused")
    : (t(`status.${application.status}`) ?? application.status_title ?? application.status);

  return (
    <span className={cn("flex min-w-0 items-center gap-1.5", className)}>
      <span className={cn("size-1.5 shrink-0 rounded-full", DOT_TONES[variant] ?? DOT_TONES.secondary)} />
      <span className="truncate text-xs text-muted-foreground">{label}</span>
    </span>
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
      {/* Wraps. It was `truncate` inside a 125px column, which cut "Setting up
          PHP for this application" to "Setting up PHP for t…" — and on a
          FAILED row the same cap took the reason, which is the one thing on
          that row worth reading. No tooltip either, so the rest existed
          nowhere. A taller row is the cheaper of the two. */}
      {provisioning ? (
        <p className="max-w-40 text-xs text-pretty text-muted-foreground">
          {provisionStepLabel(application.steps.at(-1), t, "details.")}
        </p>
      ) : null}
      {/* Where it stopped, not the support id.

          This printed a bare 36-character UUID in red mono — no label, in a
          Status column, unactionable. The detail page one click away shows the
          same value as "Support reference: …" with a Copy button, and leads
          with "Stopped at: Setting up PHP for this site" — the half that says
          what happened. The list was showing the half that only helps somebody
          else.

          Falls back to the reference when the API sends no failed_step, so a
          row is never left with nothing to say. */}
      {reference ? (
        /*
           Only when the step is one we can actually name. `provisionStepLabel`
           falls back to "Completed a step" for anything unrecognised — wording
           written for the progress case, where it reads correctly. Dropped into
           this sentence it produced "Stopped at: Completed a step", which is
           worse than the id it replaced: the id was merely unhelpful, that is
           nonsense. Caught by rendering it, not by reading it.
        */
        // The server named the cause. It is localized already and it is a
        // better answer than the step: "Killed — the server ran out of memory"
        // tells someone what to do, "Stopped at: Installing" does not.
        application.failed_reason_title ? (
          /* Wrapped, unlike the reference below. A truncated UUID is still
             recognisable as that UUID; a truncated sentence is not a sentence.
             "Could not create the PHP pool" was arriving as "Could not create
             the…", which is the reason for the failure with the reason
             removed. */
          <p className="max-w-52 text-xs text-pretty text-destructive">
            {application.failed_reason_title}
          </p>
        ) : PROVISION_STEPS.has(application.failed_step) ? (
          <p className="max-w-52 text-xs text-pretty text-destructive">
            {/* The detail page's own string, not a second one that means the
                same thing — the two screens describe one failure. */}
            {t("details.failedAt", {
              step: provisionStepLabel(application.failed_step, t, "details."),
            })}
          </p>
        ) : (
          /*
             Bounded like its two siblings, which it was not.
             
             A support reference is a 36-character UUID in mono, and this was the
             one branch of the three with no width on it, so it ran straight out
             of a 14%-wide Status column and into Owner. Reported from a
             screenshot of exactly that.
             
             `max-w-52 truncate` rather than a wrap: the column is fixed-width
             and a second line would shift every row beneath it. A partial
             reference is still recognisable as one, and the detail page one
             click away shows it in full with a Copy button.
          */
          <p className="max-w-52 truncate font-mono text-xs text-destructive">
            {application.reference}
          </p>
        )
      ) : null}
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
