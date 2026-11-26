import { useFormatter, useNow, useTranslations } from "next-intl";
import { Loader2 } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { serverTimeToEpoch } from "@/lib/cron-jobs/schedule";
import { runDuration } from "@/lib/cron-jobs/run-duration";
import { HoverHint } from "@/components/ui/hover-hint";

const VARIANT = { succeeded: "success", failed: "destructive", interrupted: "warning", running: "muted" };

export function RunStatusBadge({ status, exitCode = null }) {
  const t = useTranslations("cronJobs.lastRun");
  const known = Object.hasOwn(VARIANT, status);
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
  if (!run) return <span className="whitespace-normal text-muted-foreground">{t("notYet")}</span>;

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
      {when ? (
        <HoverHint content={at}><span className="text-xs whitespace-normal text-muted-foreground" suppressHydrationWarning>
          {when}
        </span></HoverHint>
      ) : null}
      {took ? <span className="-mt-1 text-xs whitespace-normal text-muted-foreground">{t("took", { duration: took })}</span> : null}
    </div>
  );
}
