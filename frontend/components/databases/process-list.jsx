"use client";

import { useEffect, useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Activity, ChevronDown, ChevronUp, Clock, Square, Timer, SearchX } from "lucide-react";
import { EmptyState } from "@/components/data-table/empty-state";
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
// A long-running query's card takes the alarm colour on its edge.
const CARD_EDGE = {
  destructive: "border-destructive/50",
  warning: "border-warning/50",
  neutral: "",
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

// Idle connections are counted, not listed, so a stuck query is not buried.
// `fill`: the card takes its parent's height and the list scrolls inside it, with no
// "Show all" cap: beside the chart it matches the chart's height (Krishna, 8 Oct).
export function ProcessList({ engine, processes: initial = [], canManage, connections = [], fill = false }) {
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
  // Queries whose full text is open; ids, so a refresh keeps them open.
  const [openQueries, setOpenQueries] = useState(() => new Set());
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
  const shown = expanded || fill
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
      <Card className={cn("gap-0 overflow-hidden py-0", fill && "h-full")}>
        <div className="flex shrink-0 flex-col gap-2 border-b px-5 py-3.5 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
          <div className="flex items-center gap-2.5">
            <div>
              <h2 className="text-[15px] font-semibold tracking-tight">
                {t("processes")}
              </h2>
              <p className="text-sm text-muted-foreground">
                {t("processesDescription")}
              </p>
            </div>
          </div>

          {/* The running count answers "is anything stuck?" in the header; idle
              connections stay a count. */}
          <span className="min-w-0 text-sm tabular-nums text-muted-foreground">
            {t("runningCount", { count: running.length })}
            {idle.length > 0 ? ` · ${t("idleCount", { count: idle.length })}` : ""}
          </span>
        </div>

        {filterable ? (
          // Wraps rather than switching on the viewport: the card can sit in a narrow column.
          <div className="flex shrink-0 flex-wrap items-center gap-2 border-b px-5 py-3">
            <LocalSearchInput
              value={query}
              onChange={setQuery}
              placeholder={t("searchQueries")}
              className="min-w-48 flex-1 sm:max-w-none"
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

        <CardContent className={cn("px-5 py-0", fill && "flex min-h-0 flex-1 flex-col")}>
          {active.length === 0 ? (
            // "Nothing running" and "nothing matched your filter" are different
            // states; only the latter can be cleared.
            <div className="space-y-2 py-5 text-center">
              <EmptyState
                compact
                icon={filtered ? SearchX : Activity}
                badge={filtered ? "search" : null}
                title={filtered ? t("noMatches") : t("noProcesses")}
              />
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
            // One small card per query, as in the redesign; expanded scrolls inside a
            // fixed height so the chart beside it stays put.
            <div
              className={cn(
                "space-y-3 py-4",
                expanded && "max-h-[32rem] overflow-y-auto",
                // Stacked (below xl) it still stops at 32rem; beside the chart, the card's height.
                fill && "-mx-5 max-h-[32rem] min-h-0 flex-1 overflow-y-auto px-5 xl:max-h-none",
              )}
            >
              {shown.map((process) => {
                const level = tone(process.time);
                const open = openQueries.has(process.id);
                const stopReason = !canManage
                  ? t("noPermission")
                  : isPanelProcess(process, ownUsers)
                    ? t("cannotStopOwn")
                    : null;
                return (
                  <div
                    key={process.id}
                    className={cn("space-y-3 rounded-xl border bg-card p-3", CARD_EDGE[level])}
                  >
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <span className="inline-flex min-w-0 items-center gap-1.5 rounded-md bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary">
                        <Activity className="size-3.5 shrink-0" aria-hidden />
                        <span className="truncate">{process.state || process.command || "—"}</span>
                      </span>
                      {/* The elapsed time, coloured once it is worth worrying about. */}
                      <span
                        className={cn(
                          "inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-sm font-semibold tabular-nums",
                          TIME_STYLE[level],
                        )}
                      >
                        <Clock className="size-3.5" />
                        {duration(process.time, t)}
                      </span>
                    </div>
                    {level === "destructive" ? (
                      <Badge variant="destructive">{t("longRunning")}</Badge>
                    ) : null}

                    <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1 text-[13px]">
                      <dt className="text-muted-foreground">{t("meta.database")}</dt>
                      <dd className="truncate font-mono">{process.db || "—"}</dd>
                      <dt className="text-muted-foreground">{t("meta.user")}</dt>
                      <dd className="truncate font-mono">
                        {process.user ?? "—"}
                        {process.host ? `@${process.host}` : ""}
                      </dd>
                    </dl>

                    {/* Two lines at rest in a narrow column; the whole query on request. */}
                    {process.query ? (
                      <div className="space-y-1">
                        <pre
                          className={cn(
                            "whitespace-pre-wrap break-words rounded-md bg-muted px-2.5 py-2 font-mono text-xs leading-relaxed text-foreground dark:bg-muted/60",
                            !open && "line-clamp-2",
                          )}
                        >
                          {process.query}
                        </pre>
                        <button
                          type="button"
                          className="text-xs font-medium text-primary hover:underline"
                          aria-expanded={open}
                          onClick={() =>
                            setOpenQueries((current) => {
                              const next = new Set(current);
                              if (next.has(process.id)) next.delete(process.id);
                              else next.add(process.id);
                              return next;
                            })
                          }
                        >
                          {open ? t("queryShowLess") : t("queryShowFull")}
                        </button>
                      </div>
                    ) : null}

                    <ReasonTooltip reason={stopReason}>
                      <Button
                        variant="destructive"
                        size="sm"
                        className="w-full"
                        disabled={Boolean(stopReason)}
                        onClick={() => stop.open(process)}
                      >
                        <Square className="size-3.5" />
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
