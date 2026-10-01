"use client";

import { useEffect, useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Activity, ChevronDown, ChevronUp, Clock, Square, Timer } from "lucide-react";
import { cn } from "@/lib/utils";
import { getProcesses, killProcess } from "@/lib/api/databases";
import { dbProcessesResponseSchema } from "@/lib/schemas/database";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { LocalSearchInput } from "@/components/data-table/local-search-input";
import { useConfirmAction } from "@/hooks/use-confirm-action";
import { isPanelProcess, panelUsernames } from "@/lib/databases/own-connection";
import { activeQueries, isIdle } from "@/lib/databases/health";

const POLL_MS = 5000;

// Ten seconds is worth noticing; a minute is likely the one to kill.
const SLOW_SECONDS = 10;
const STUCK_SECONDS = 60;

// Keeps a busy engine from pushing the 24h chart down. Matches `PREVIEW_COUNT` on the dashboard's process card.
const VISIBLE_COUNT = 3;

function tone(seconds) {
  if ((seconds ?? 0) >= STUCK_SECONDS) return "destructive";
  if ((seconds ?? 0) >= SLOW_SECONDS) return "warning";
  return "neutral";
}

// The accent bar alone marks the row; no tinted background.
const ROW_ACCENT = {
  destructive: "border-l-destructive",
  warning: "border-l-warning",
  neutral: "border-l-transparent",
};

const TIME_STYLE = {
  destructive: "bg-destructive/10 text-destructive",
  warning: "bg-warning/15 text-warning",
  neutral: "bg-muted text-muted-foreground",
};

/** "2m 8s" rather than "128s". */
function duration(seconds, t) {
  const total = Math.max(0, Math.round(seconds ?? 0));
  if (total < 60) return t("durationSeconds", { seconds: total });
  const minutes = Math.floor(total / 60);
  if (minutes < 60) return t("durationMinutes", { minutes, seconds: total % 60 });
  return t("durationHours", { hours: Math.floor(minutes / 60), minutes: minutes % 60 });
}

function Fact({ label, last, children }) {
  return (
    <span className="inline-flex min-w-0 items-baseline gap-1">
      <span className="text-muted-foreground">{label}:</span>
      <span className="truncate text-foreground">{children}</span>
      {/* The separator belongs to the preceding fact so it never wraps alone;
          hidden below `sm`, where each fact has its own line. */}
      {last ? null : (
        <span className="ml-1.5 hidden text-muted-foreground/60 sm:inline" aria-hidden>
          ·
        </span>
      )}
    </span>
  );
}

// Idle connections are counted, not listed, so a stuck query is not buried.
export function ProcessList({ engine, processes: initial = [], canManage, connections = [] }) {
  const t = useTranslations("databases.monitor");
  const { refreshAndWait } = useRefresh();
  const [polled, setPolled] = useState(null);
  // Holds the row, in-flight flag and last failure, so a refused stop is
  // explained in the dialog.
  const stop = useConfirmAction();
  // The panel's own admin accounts: on a quiet server its monitoring
  // connection may be the only row, and stopping it breaks this screen.
  const ownUsers = panelUsernames(connections, engine);
  const [expanded, setExpanded] = useState(false);
  const [query, setQuery] = useState("");
  const [slowOnly, setSlowOnly] = useState(false);

  const all = polled ?? initial;
  const idle = all.filter(isIdle);
  // Longest-running first. Both tests come from lib/databases/health.js, shared
  // with the stat cards so the headline and the list cannot disagree.
  const running = activeQueries(all);

  // Search covers everything a row displays: statement, database, user, host.
  const term = query.trim().toLowerCase();
  const active = running.filter((p) => {
    if (slowOnly && (p.time ?? 0) < SLOW_SECONDS) return false;
    if (!term) return true;
    return [p.query, p.db, p.user, p.host, p.state]
      .filter(Boolean)
      .some((value) => String(value).toLowerCase().includes(term));
  });

  // Controls only appear once there is a list worth filtering.
  const filterable = running.length > VISIBLE_COUNT;
  const filtered = term !== "" || slowOnly;

  // The cap hides only short queries because the list is sorted longest-first;
  // anything past the stuck threshold is always shown.
  const shown = expanded
    ? active
    : active.filter((p, i) => i < VISIBLE_COUNT || tone(p.time) === "destructive");
  const hidden = active.length - shown.length;

  useEffect(() => {
    const controller = new AbortController();
    const id = setInterval(async () => {
      try {
        const { data } = await getProcesses(engine, { signal: controller.signal });
        const parsed = dbProcessesResponseSchema.safeParse(data);
        if (parsed.success) setPolled(parsed.data.processes);
      } catch {
        // A dropped poll isn't worth reporting; the next one runs in 5s.
      }
    }, POLL_MS);

    return () => {
      controller.abort();
      clearInterval(id);
    };
  }, [engine]);

  async function kill() {
    await stop.run(() => killProcess(stop.target.id, engine), {
      fallback: t("killFailed"),
      onDone: async () => {
        // Wait for the server's list so the toast and close never sit over the
        // stopped query.
        await refreshAndWait();
        setPolled(null);
        toast.success(t("killed"));
      },
    });
  }

  return (
    <>
      <Card className="gap-0 overflow-hidden py-0">
        <div className="flex flex-col gap-2 border-b px-5 py-3.5 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
          <div className="flex items-center gap-2.5">
            <span className="flex shrink-0 items-center justify-center text-muted-foreground">
              <Activity className="size-3.5" />
            </span>
            <div>
              <h2 className="text-base font-semibold tracking-tight">
                {t("processes")}
              </h2>
              <p className="text-sm text-muted-foreground">
                {t("processesDescription")}
              </p>
            </div>
          </div>

          {/* The running count answers "is anything stuck?" in the header; idle
              connections stay a count. */}
          <span className="shrink-0 text-sm tabular-nums text-muted-foreground">
            {t("runningCount", { count: running.length })}
            {idle.length > 0 ? ` · ${t("idleCount", { count: idle.length })}` : ""}
          </span>
        </div>

        {filterable ? (
          <div className="flex flex-col gap-2 border-b px-5 py-3 sm:flex-row sm:items-center">
            <LocalSearchInput
              value={query}
              onChange={setQuery}
              placeholder={t("searchQueries")}
            />
            <Button
              variant={slowOnly ? "secondary" : "outline"}
              size="sm"
              className="shrink-0"
              onClick={() => setSlowOnly((prev) => !prev)}
              aria-pressed={slowOnly}
            >
              <Timer className="size-4" />
              {t("slowOnly", { seconds: SLOW_SECONDS })}
            </Button>
          </div>
        ) : null}

        <CardContent className="px-5 py-0">
          {active.length === 0 ? (
            // "Nothing running" and "nothing matched your filter" are different
            // states; only the latter can be cleared.
            <div className="space-y-2 py-8 text-center">
              <p className="text-sm text-muted-foreground">
                {filtered ? t("noMatches") : t("noProcesses")}
              </p>
              {filtered ? (
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => {
                    setQuery("");
                    setSlowOnly(false);
                  }}
                >
                  {t("clearFilters")}
                </Button>
              ) : null}
            </div>
          ) : (
            // Expanded scrolls inside a fixed height so the chart stays put.
            <div
              className={cn(
                "-mx-5 divide-y",
                expanded && "max-h-[26rem] overflow-y-auto",
              )}
            >
              {shown.map((process) => {
                const level = tone(process.time);
                return (
                  <div
                    key={process.id}
                    className={cn(
                      "group flex flex-col gap-3 border-l-2 py-3.5 pl-4 pr-5 transition-colors hover:bg-muted/30 sm:flex-row sm:items-start sm:justify-between",
                      ROW_ACCENT[level],
                    )}
                  >
                    <div className="min-w-0 flex-1 space-y-3">
                      <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                        {/* The elapsed time leads. */}
                        <span
                          className={cn(
                            "inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-sm font-semibold tabular-nums",
                            TIME_STYLE[level],
                          )}
                        >
                          <Clock className="size-3.5" />
                          {duration(process.time, t)}
                        </span>
                        {/* Named, not only signalled by the red bar. */}
                        {level === "destructive" ? (
                          <Badge
                            variant="outline"
                            className="border-destructive/40 font-normal text-destructive"
                          >
                            {t("longRunning")}
                          </Badge>
                        ) : null}
                      </div>

                      {/* One line, dot-separated, always in the same order. */}
                      <p className="flex flex-wrap items-baseline gap-x-3 gap-y-1.5 text-[13px] leading-relaxed">
                        <Fact label={t("meta.database")}>
                          <span className="font-mono">{process.db || "—"}</span>
                        </Fact>
                        <Fact label={t("meta.user")}>
                          <span className="font-mono">
                            {process.user ?? "—"}
                            {process.host ? `@${process.host}` : ""}
                          </span>
                        </Fact>
                        <Fact label={t("meta.state")} last>
                          {process.state || process.command || "—"}
                        </Fact>
                      </p>

                      {/* Wrapped, not truncated: a cut-off query says nothing. */}
                      {process.query ? (
                        <pre className="overflow-x-auto whitespace-pre-wrap break-words rounded-md border bg-muted px-3 py-2.5 font-mono text-[13px] leading-relaxed text-foreground dark:bg-muted/60">
                          {process.query}
                        </pre>
                      ) : null}
                    </div>

                    {/* Legible at rest: touch devices never hover. Overrides the outline variant's `bg-muted` hover. */}
                    <ReasonTooltip
                      reason={
                        !canManage
                          ? t("noPermission")
                          : isPanelProcess(process, ownUsers)
                            ? t("cannotStopOwn")
                            : null
                      }
                    >
                      <Button
                        variant="outline"
                        disabled={!canManage || isPanelProcess(process, ownUsers)}
                        className={cn(
                          "shrink-0 font-medium text-foreground/80 transition-colors",
                          "group-hover:border-destructive/40 group-hover:text-destructive",
                          "hover:border-destructive/60 hover:bg-destructive/15 hover:text-destructive",
                          "active:bg-destructive/25",
                          "focus-visible:border-destructive/40 focus-visible:ring-destructive/20",
                        )}
                        onClick={() => stop.open(process)}
                      >
                        <Square className="size-4" />
                        {t("stopQuery")}
                      </Button>
                    </ReasonTooltip>
                  </div>
                );
              })}
            </div>
          )}

          {/* Only when the cap is actually hiding something. */}
          {hidden > 0 || expanded ? (
            <div className="-mx-5 border-t px-5 py-2">
              {/* The ghost variant fills on hover and on aria-expanded; both
                  fills are neutralised so text colour carries the state. */}
              <Button
                variant="ghost"
                size="sm"
                // aria-expanded beats hover on equal specificity, so the
                // expanded state needs the compound variant.
                className="w-full text-muted-foreground hover:bg-transparent hover:text-foreground aria-expanded:bg-transparent aria-expanded:text-muted-foreground aria-expanded:hover:text-foreground dark:hover:bg-transparent"
                onClick={() => setExpanded((prev) => !prev)}
                aria-expanded={expanded}
              >
                {expanded ? (
                  <ChevronUp className="size-4" />
                ) : (
                  <ChevronDown className="size-4" />
                )}
                {expanded ? t("showLess", { count: active.length }) : t("showAll", { count: hidden })}
              </Button>
            </div>
          ) : null}
        </CardContent>
      </Card>

      <ConfirmDialog
        open={stop.isOpen}
        onOpenChange={stop.setOpen}
        icon={Square}
        tone="destructive"
        title={t("killTitle")}
        description={t("killDescription")}
        cancelLabel={t("cancel")}
        confirmLabel={stop.pending ? t("killing") : t("stopQuery")}
        pending={stop.pending}
        error={stop.error}
        onConfirm={kill}
      />
    </>
  );
}
