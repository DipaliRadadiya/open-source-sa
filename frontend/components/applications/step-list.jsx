import { Check, CircleAlert, Loader2 } from "lucide-react";
import { cn } from "@/lib/utils";

/**
 * The ringed list of steps a long job has finished, plus a live row for the one
 * it is on.
 *
 * Shared by provisioning and by deploying because they are the same question —
 * "is my thing being built?" — answered from the same `steps[]` field. The
 * deploy card used to lay them out in two columns, which turned a sequence into
 * something you read down the left in the wrong order.
 *
 * Only completed steps get a row. The API reports what *finished*, never what
 * started, and which steps run at all depends on the site — a deploy script
 * only if one is set, workers only if there are any — so a greyed-out list of
 * what is still to come would sometimes be a list of things that never happen.
 */
function Marker({ tone, children }) {
  return (
    <span
      className={cn(
        "flex size-5 shrink-0 items-center justify-center rounded-full border",
        tone === "done" && "border-success bg-success/10 text-success",
        tone === "working" && "border-primary bg-primary/10 text-primary",
        tone === "failed" && "border-destructive bg-destructive/10 text-destructive",
      )}
      aria-hidden
    >
      {children}
    </span>
  );
}

export function StepList({
  steps = [],
  working = false,
  workingLabel,
  failedStep = null,
  label,
  className,
}) {
  return (
    // Rows appear one at a time while the user watches, so announce the
    // additions rather than leaving a screen reader on a frozen page.
    <ol className={cn("space-y-2.5", className)} aria-live="polite">
      {steps.map((step, index) => (
        <li key={`${step}-${index}`} className="flex items-center gap-3 text-sm">
          <Marker tone="done">
            <Check className="size-3" />
          </Marker>
          <span className="text-muted-foreground">{label(step)}</span>
        </li>
      ))}

      {working ? (
        <li className="flex items-center gap-3 text-sm">
          <Marker tone="working">
            <Loader2 className="size-3 animate-spin" />
          </Marker>
          <span className="font-medium">{workingLabel}</span>
        </li>
      ) : null}

      {failedStep ? (
        <li className="flex items-center gap-3 text-sm">
          <Marker tone="failed">
            <CircleAlert className="size-3" />
          </Marker>
          <span className="font-medium text-destructive">{label(failedStep)}</span>
        </li>
      ) : null}
    </ol>
  );
}
