import { useCallback, useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { ChevronDown, History, Loader2, RefreshCw, ScrollText, SquareArrowOutUpRight } from "lucide-react";
import Link from "@/components/ui/app-link";
import { getCronjobRuns } from "@/lib/api/cronjobs";
import { cronjobRunsResponseSchema } from "@/lib/schemas/cronjob";
import { apiMessage } from "@/lib/api/error-message";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Caution } from "@/components/ui/caution";
import { CopyButton } from "@/components/ui/copy-button";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import { Skeleton } from "@/components/ui/skeleton";
import { EmptyState } from "@/components/data-table/empty-state";
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "@/components/ui/sheet";
import { RunStatusBadge, useRunDuration } from "@/components/cron-jobs/cronjob-last-run";

const POLL_MS = 5000;

// The job's latest runs with what each printed, read from its log on open.
export function RunHistorySheet({ job, open, onOpenChange, canViewLogs = false }) {
  const t = useTranslations("cronJobs.history");
  // { runs, timezone } | { error } | null while the first read is out.
  const [state, setState] = useState(null);
  const [loading, setLoading] = useState(false);

  // Resolves to the next state, or null when aborted; state is set only in callbacks, never
  // synchronously inside an effect.
  const request = useCallback(
    (signal) =>
      getCronjobRuns(job.id, { signal }).then(
        ({ data }) => {
          const parsed = cronjobRunsResponseSchema.safeParse(data);
          return parsed.success ? parsed.data : { error: t("failed") };
        },
        // An unreadable log is a 500 with a reference, never an empty list: say so.
        (error) => (signal?.aborted ? null : { error: apiMessage(error, t("failed")) }),
      ),
    [job.id, t],
  );

  function reload() {
    setLoading(true);
    request().then((next) => {
      if (next) setState(next);
      setLoading(false);
    });
  }

  useEffect(() => {
    if (!open) return undefined;
    const controller = new AbortController();
    request(controller.signal).then((next) => {
      if (next && !controller.signal.aborted) setState(next);
    });
    return () => controller.abort();
  }, [open, request]);

  // A run still going is re-read until it ends; nothing polls otherwise.
  const anyRunning = state?.runs?.some((run) => run.status === "running");
  useEffect(() => {
    if (!open || !anyRunning) return undefined;
    const controller = new AbortController();
    const id = setTimeout(() => {
      request(controller.signal).then((next) => {
        if (next && !controller.signal.aborted) setState(next);
      });
    }, POLL_MS);
    return () => {
      controller.abort();
      clearTimeout(id);
    };
  }, [open, anyRunning, state, request]);

  return (
    <Sheet
      open={open}
      onOpenChange={(next) => {
        if (!next) setState(null);
        onOpenChange?.(next);
      }}
    >
      <SheetContent className="data-[side=right]:w-full data-[side=right]:sm:max-w-xl">
        <SheetHeader>
          <SheetTitle className="flex items-center gap-2">
            <History className="size-4" aria-hidden />
            {t("title")}
          </SheetTitle>
          <SheetDescription>
            {state?.timezone ? t("description", { name: job.name, timezone: state.timezone }) : t("descriptionNoZone", { name: job.name })}
          </SheetDescription>
          <div className="flex flex-wrap items-center gap-2 pt-1">
            <Button type="button" variant="outline" size="sm" disabled={loading} onClick={reload}>
              {loading ? <Loader2 className="size-4 animate-spin" aria-hidden /> : <RefreshCw className="size-4" aria-hidden />}
              {t("refresh")}
            </Button>
            {canViewLogs && job.log_key ? (
              <Button asChild variant="ghost" size="sm">
                {/* New tab: this leaves Cron Jobs for the server-wide Logs page. */}
                <Link href={`/logs?source=${encodeURIComponent(job.log_key)}`} target="_blank" rel="noreferrer">
                  <ScrollText className="size-4" aria-hidden />
                  {t("fullLog")}
                  <SquareArrowOutUpRight className="size-3.5 text-muted-foreground" aria-hidden />
                </Link>
              </Button>
            ) : null}
          </div>
        </SheetHeader>

        <div className="min-h-0 flex-1 overflow-y-auto px-4 pb-4">
          {state === null ? (
            <div className="space-y-3" aria-busy="true">
              {[0, 1, 2].map((i) => (
                <Skeleton key={i} className="h-16 w-full" />
              ))}
            </div>
          ) : state.error ? (
            <Caution tone="destructive" size="md" action={<Button size="sm" variant="outline" onClick={reload}>{t("retry")}</Button>}>
              <p>{state.error}</p>
            </Caution>
          ) : state.runs.length === 0 ? (
            <EmptyState compact icon={History} badge={null} title={t("empty")} />
          ) : (
            <ol className="space-y-3">
              {state.runs.map((run, index) => (
                <RunItem key={`${run.started_at ?? ""}-${run.finished_at ?? ""}-${index}`} run={run} />
              ))}
            </ol>
          )}
        </div>
      </SheetContent>
    </Sheet>
  );
}

function RunItem({ run }) {
  const t = useTranslations("cronJobs.history");
  const tLast = useTranslations("cronJobs.lastRun");
  const duration = useRunDuration();
  const took = duration(run.duration_seconds);
  const output = run.output ?? "";
  const times = [
    run.started_at ? t("started", { at: run.started_at }) : null,
    run.finished_at ? t("finished", { at: run.finished_at }) : null,
    took ? tLast("took", { duration: took }) : null,
  ].filter(Boolean);

  return (
    <li className="rounded-xl border">
      <div className="space-y-1.5 px-3 py-2.5">
        <div className="flex flex-wrap items-center gap-1.5">
          <RunStatusBadge status={run.status} exitCode={run.exit_code} />
          {run.manual ? (
            <Badge variant="outline" className="font-normal">
              {tLast("manual")}
            </Badge>
          ) : null}
        </div>
        {times.length ? <p className="text-xs text-muted-foreground tabular-nums">{times.join(" · ")}</p> : null}
        {run.status === "interrupted" ? <p className="text-xs text-muted-foreground">{tLast("interruptedHint")}</p> : null}
        {run.overlapped ? <p className="text-xs text-warning">{t("overlapped")}</p> : null}
      </div>

      {output ? (
        <Collapsible className="border-t">
          <CollapsibleTrigger className="group flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-xs font-medium hover:bg-muted/40">
            <span className="group-data-[state=open]:hidden">{t("showOutput")}</span>
            <span className="hidden group-data-[state=open]:inline">{t("hideOutput")}</span>
            <ChevronDown className="size-4 shrink-0 transition-transform group-data-[state=open]:rotate-180" aria-hidden />
          </CollapsibleTrigger>
          <CollapsibleContent className="space-y-2 px-3 pb-3">
            {run.output_truncated ? <p className="text-xs text-muted-foreground">{t("truncated")}</p> : null}
            <div className="relative">
              <pre className={cn("max-h-80 overflow-auto rounded-lg bg-muted/50 p-3 pr-10 font-mono text-xs whitespace-pre-wrap break-all")}>{output}</pre>
              <CopyButton value={output} label={t("copyOutput")} className="absolute top-1.5 right-1.5" />
            </div>
          </CollapsibleContent>
        </Collapsible>
      ) : (
        <p className="border-t px-3 py-2 text-xs text-muted-foreground">{t("noOutput")}</p>
      )}
    </li>
  );
}
