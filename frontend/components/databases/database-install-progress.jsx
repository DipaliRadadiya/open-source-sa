import { useTranslations } from "next-intl";
import {
  ChevronDown,
  CircleAlert,
  Loader2,
  SquareTerminal,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Progress } from "@/components/ui/progress";
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@/components/ui/collapsible";

/**
 * One API-driven database installation lifecycle for Setup and both database
 * page states. The backend owns stage/failure wording; this component owns only
 * the surrounding controls and accessibility text.
 */
export function DatabaseInstallProgress({
  progress,
  label,
  slow = false,
  pollIssue = false,
  className,
}) {
  const t = useTranslations("databaseInstallProgress");
  if (!progress) return null;

  const failed = progress.status === "failed";
  const title =
    progress.current_step_title ??
    (failed
      ? t("failed")
      : progress.current_step === "queued"
        ? t("queued")
        : t("working"));

  return (
    <div
      aria-busy={!failed}
      className={cn(
        "space-y-3 rounded-lg border bg-muted/20 p-3",
        failed && "border-destructive/30 bg-destructive/5",
        className,
      )}
    >
      <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
        <p
          aria-live="polite"
          className={cn(
            "flex min-w-0 items-start gap-2 text-sm font-medium",
            failed && "text-destructive",
          )}
        >
          {failed ? (
            <CircleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
          ) : (
            <Loader2
              className="mt-0.5 size-4 shrink-0 animate-spin text-primary"
              aria-hidden
            />
          )}
          <span>{title}</span>
        </p>

        {progress.started_at_human ? (
          <span className="text-xs text-muted-foreground">
            {t("started", { when: progress.started_at_human })}
          </span>
        ) : null}
      </div>

      {!failed ? (
        <Progress
          indeterminate
          role="progressbar"
          aria-label={t("progressLabel", { name: label })}
          aria-valuetext={title}
          className="h-2"
        />
      ) : null}

      {failed ? (
        <div className="space-y-1">
          <p className="text-sm text-destructive">
            {progress.message || t("failureFallback")}
          </p>
          {progress.reference ? (
            <p className="font-mono text-xs break-words text-muted-foreground">
              {t("reference", { reference: progress.reference })}
            </p>
          ) : null}
        </div>
      ) : (
        <p
          className={cn(
            "text-xs text-muted-foreground",
            pollIssue && "text-warning",
          )}
        >
          {pollIssue
            ? t("pollIssue")
            : slow
              ? t("takingLonger")
              : t("safeToLeave")}
        </p>
      )}

      {progress.output ? (
        <Collapsible
          defaultOpen={failed}
          className="group/output border-t pt-2"
        >
          <CollapsibleTrigger asChild>
            <Button variant="ghost" size="sm" className="-ml-2 h-8 gap-2 px-2">
              <SquareTerminal className="size-4" aria-hidden />
              {t("output")}
              <ChevronDown className="size-4 transition-transform group-data-[state=open]/output:rotate-180" />
            </Button>
          </CollapsibleTrigger>
          <CollapsibleContent className="pt-2">
            <pre className="max-h-80 overflow-auto rounded-md border bg-zinc-950 p-3 font-mono text-xs leading-relaxed whitespace-pre-wrap text-zinc-100">
              {progress.output}
            </pre>
          </CollapsibleContent>
        </Collapsible>
      ) : null}
    </div>
  );
}
