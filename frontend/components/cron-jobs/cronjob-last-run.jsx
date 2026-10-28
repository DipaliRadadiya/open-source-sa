import { useFormatter, useNow, useTranslations } from "next-intl";
import { Loader2 } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { serverTimeToEpoch } from "@/lib/cron-jobs/schedule";
import { runDuration } from "@/lib/cron-jobs/run-duration";

const VARIANT = { succeeded: "success", failed: "destructive", interrupted: "warning", running: "muted" };

/** A run's outcome as a badge, and a failure's exit code after it. */
export function RunStatusBadge({ status, exitCode = null }) {
  const t = useTranslations("cronJobs.lastRun");
  const known = Object.hasOwn(VARIANT, status);
  // The exit code beside the badge, not in it: a badge never wraps, and "Fehlgeschlagen · Code 2"
  // was wider than a phone card's column. Both sit in their parent's wrapping row.
  return (
    <>
      <Badge variant={VARIANT[status] ?? "muted"} className="gap-1 font-normal">
        {status === "running" ? <Loader2 className="size-3 animate-spin" aria-hidden /> : null}
        {known ? t(status) : status}
      </Badge>
      {status === "failed" && exitCode != null ? (
        <span className="text-xs text-muted-foreground tabular-nums">{t("exitCode", { code: exitCode })}</span>
      ) : null}
    </>
  );
}

export function useRunDuration() {
  const t = useTranslations("cronJobs.duration");
  return (seconds) => {
    const d = runDuration(seconds);
    return d ? t(d.key, d.values) : null;
  };
}

// The list's "did it work?": the latest run, or Running now while one is going.
export function CronjobLastRun({ job }) {
  const t = useTranslations("cronJobs.lastRun");
  const format = useFormatter();
  const now = useNow({ updateInterval: 15000 });
  const duration = useRunDuration();
  const run = job.last_run;

  if (job.running) {
    return (
      <div className="flex flex-col items-start gap-1">
        <RunStatusBadge status="running" />
      </div>
    );
  }
  if (!run) return <span className="text-muted-foreground">{t("notYet")}</span>;

  // Server clock, read as the server wrote it, then said relative to now.
  const at = run.finished_at ?? run.started_at;
  const epoch = serverTimeToEpoch(at, job.timezone);
  const when = epoch === null ? at : format.relativeTime(Math.min(epoch, now.getTime()), now);
  const took = duration(run.duration_seconds);

  return (
    <div className="flex flex-col items-start gap-1">
      <div className="flex flex-wrap items-center gap-1.5">
        <RunStatusBadge status={run.status} exitCode={run.exit_code} />
        {run.manual ? (
          <Badge variant="outline" className="font-normal">
            {t("manual")}
          </Badge>
        ) : null}
      </div>
      {/* Two lines, not "when · took": one line made the column too wide for the table at 1280px. */}
      {when ? (
        <span className="text-xs whitespace-nowrap text-muted-foreground" title={at} suppressHydrationWarning>
          {when}
        </span>
      ) : null}
      {took ? <span className="-mt-1 text-xs whitespace-nowrap text-muted-foreground">{t("took", { duration: took })}</span> : null}
    </div>
  );
}
