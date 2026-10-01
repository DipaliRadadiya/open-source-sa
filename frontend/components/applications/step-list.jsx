import { Check, CircleAlert, Loader2 } from "lucide-react";
import { cn } from "@/lib/utils";

// Only completed steps get a row: the API reports what finished, never what started.
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
  // The API's list ends with the failed step; drop it so it is not drawn twice.
  const done =
    failedStep && steps[steps.length - 1] === failedStep ? steps.slice(0, -1) : steps;

  return (
    // Rows appear one at a time, so announce additions to screen readers.
    <ol className={cn("space-y-2.5", className)} aria-live="polite">
      {done.map((step, index) => (
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
