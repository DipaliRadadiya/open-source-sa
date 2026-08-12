"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Activity, ChevronDown, ChevronUp, Clock, Loader2, Square, Timer } from "lucide-react";
import { cn } from "@/lib/utils";
import { getProcesses, killProcess } from "@/lib/api/databases";
import { dbProcessesResponseSchema } from "@/lib/schemas/database";
import { apiMessage } from "@/lib/api/error-message";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { LocalSearchInput } from "@/components/data-table/local-search-input";

const POLL_MS = 5000;

/**
 * A long-running query is what "the site is slow" usually turns out to be, so
 * anything past this is marked rather than left for the reader to spot in a
 * column of numbers. Two thresholds, not one: ten seconds is worth noticing,
 * a full minute is the thing you came here to kill.
 */
const SLOW_SECONDS = 10;
const STUCK_SECONDS = 60;

/**
 * How many rows the list shows before it offers to expand.
 *
 * A busy engine can have fifty connections doing work, and an unbounded list
 * would push the 24h chart arbitrarily far down — the page's height would
 * depend on how bad a day the database is having. Neither RDS Performance
 * Insights nor Percona PMM stacks an unbounded live list above a history
 * chart: the list is always bounded, or the two live on separate screens.
 * See memory/research-database-health-layout.md. Three, matching
 * `PREVIEW_COUNT` on the dashboard's process card — the two collapsed lists in
 * this product should agree on what "a glance" is.
 */
const VISIBLE_COUNT = 3;

function tone(seconds) {
  if ((seconds ?? 0) >= STUCK_SECONDS) return "destructive";
  if ((seconds ?? 0) >= SLOW_SECONDS) return "warning";
  return "neutral";
}

/**
 * The accent bar alone marks the row.
 *
 * The tinted row background used to stack with a red time pill, a red badge
 * and a red button, so the worst row was four shades of the same alarm. One
 * marker plus one coloured number reads as "this one" — four reads as noise.
 */
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

/** "2m 8s" beats "128s" — nobody divides by sixty while a site is down. */
function duration(seconds, t) {
  const total = Math.max(0, Math.round(seconds ?? 0));
  if (total < 60) return t("durationSeconds", { seconds: total });
  const minutes = Math.floor(total / 60);
  if (minutes < 60) return t("durationMinutes", { minutes, seconds: total % 60 });
  return t("durationHours", { hours: Math.floor(minutes / 60), minutes: minutes % 60 });
}

/**
 * One `Label: value` pair on the row's meta line.
 *
 * Inline, not stacked: label-above-value turned each row into three tall
 * blocks and the list read as a stack of debug cards. The label still has to
 * be there — `analytics · reporting@10.0.0.9` alone leaves you guessing which
 * is which — it just does not get its own line.
 */
function Fact({ label, last, children }) {
  return (
    <span className="inline-flex min-w-0 items-baseline gap-1">
      <span className="text-muted-foreground">{label}:</span>
      <span className="truncate text-foreground">{children}</span>
      {/* The separator belongs to the fact before it — as its own flex child it
          wrapped onto a line by itself on a phone. Hidden below `sm`, where
          each fact gets its own line anyway and the dot would just dangle off
          the end of one. Separators are for facts that share a line. */}
      {last ? null : (
        <span className="ml-1.5 hidden text-muted-foreground/60 sm:inline" aria-hidden>
          ·
        </span>
      )}
    </span>
  );
}

/**
 * What the engine is doing right now, refreshed every five seconds.
 *
 * Idle connections are the majority on any real server and say nothing, so they
 * are counted rather than listed — a table of forty `Sleep` rows buries the one
 * query that is actually stuck.
 */
export function ProcessList({ engine, processes: initial = [], canManage }) {
  const t = useTranslations("databases.monitor");
  const router = useRouter();
  const [polled, setPolled] = useState(null);
  const [killing, setKilling] = useState(null);
  const [pending, setPending] = useState(false);
  const [expanded, setExpanded] = useState(false);
  const [query, setQuery] = useState("");
  const [slowOnly, setSlowOnly] = useState(false);

  const all = polled ?? initial;
  const idle = all.filter((p) => (p.command ?? "").toLowerCase() === "sleep");
  // Longest-running first. The query you opened this page to find is the one
  // that has been going the longest, and it should never be the one you have
  // to hunt for down the list.
  const running = all
    .filter((p) => (p.command ?? "").toLowerCase() !== "sleep")
    .sort((a, b) => (b.time ?? 0) - (a.time ?? 0));

  // Search covers everything a row displays — the statement, the database, the
  // user and the host — because "which of these is the reporting job" is asked
  // by any of those four depending on who is asking.
  const term = query.trim().toLowerCase();
  const active = running.filter((p) => {
    if (slowOnly && (p.time ?? 0) < SLOW_SECONDS) return false;
    if (!term) return true;
    return [p.query, p.db, p.user, p.host, p.state]
      .filter(Boolean)
      .some((value) => String(value).toLowerCase().includes(term));
  });

  // The controls only exist once there is a list worth filtering; below that
  // they are chrome asking to be ignored.
  const filterable = running.length > VISIBLE_COUNT;
  const filtered = term !== "" || slowOnly;

  // The cap is only honest because the list is sorted longest-first — the rows
  // it hides are the short, fast ones. Anything past the stuck threshold is
  // exempt regardless of position, so a wedged query can never end up behind a
  // "show all" nobody clicks.
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
    setPending(true);
    try {
      await killProcess(killing.id, engine);
      toast.success(t("killed"));
      setKilling(null);
      // Drop back to the server's list so the row disappears from the same
      // place everything else on this page comes from.
      setPolled(null);
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("killFailed")));
    } finally {
      setPending(false);
    }
  }

  return (
    <>
      <Card className="gap-0 overflow-hidden py-0">
        <div className="flex flex-col gap-2 border-b px-5 py-3.5 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
          <div className="flex items-center gap-2.5">
            <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
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

          {/* The running count belongs here, not just in the rows below: the
              question this section answers is "is anything stuck?", and the
              header should answer it before you read a single query. Idle
              connections stay a count — noise in a list, information as a
              number. */}
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
            // facts, and only one of them has a way out.
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
            // Expanded scrolls inside a fixed height rather than growing the
            // page: fifty busy connections must not decide where the chart is.
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
                        {/* The elapsed time leads: it is what makes a row worth
                            reading, and it is what you sort by in your head. */}
                        <span
                          className={cn(
                            "inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-sm font-semibold tabular-nums",
                            TIME_STYLE[level],
                          )}
                        >
                          <Clock className="size-3.5" />
                          {duration(process.time, t)}
                        </span>
                        {/* Named, not left for the reader to infer from a red
                            bar: the row that matters should say why. */}
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

                      {/* The statement is the whole reason to look at this
                          list, so it gets a surface of its own rather than
                          being another line of grey text. Wrapped, not
                          truncated — a query cut at 80 characters tells you
                          nothing about what it was doing. */}
                      {/* A code block, not a line of mono text: border and
                          surface say "this is the statement", and the looser
                          leading makes a wrapped 200-character query readable
                          rather than a wall. `break-words` over `break-all` so
                          it breaks between tokens where it can. */}
                      {process.query ? (
                        <pre className="overflow-x-auto whitespace-pre-wrap break-words rounded-md border bg-muted px-3 py-2.5 font-mono text-[13px] leading-relaxed text-foreground dark:bg-muted/60">
                          {process.query}
                        </pre>
                      ) : null}
                    </div>

                    {/* Three states, deliberately separated so they cannot
                        fight each other:
                          rest          neutral outline
                          row hover     turns red — "this is what it acts on"
                          button hover  fills — "and this is the thing you press"
                        The row state changes border and text only; the fill is
                        the button's alone. Sharing the background between them
                        is what made hovering the button feel like nothing
                        happened. The outline variant also hovers to `bg-muted`,
                        which turned it grey mid-gesture — overridden here.
                        It stays a legible outlined button at rest because touch
                        devices never get a hover state at all. */}
                    <ReasonTooltip reason={canManage ? null : t("noPermission")}>
                      <Button
                        variant="outline"
                        disabled={!canManage}
                        className={cn(
                          "shrink-0 font-medium text-foreground/80 transition-colors",
                          "group-hover:border-destructive/40 group-hover:text-destructive",
                          "hover:border-destructive/60 hover:bg-destructive/15 hover:text-destructive",
                          "active:bg-destructive/25",
                          "focus-visible:border-destructive/40 focus-visible:ring-destructive/20",
                        )}
                        onClick={() => setKilling(process)}
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

          {/* Only when the cap is actually hiding something. A toggle that
              says "show all 3 of 3" is a control asking to be ignored. */}
          {hidden > 0 || expanded ? (
            <div className="-mx-5 border-t px-5 py-2">
              {/* The ghost variant fills on hover AND on aria-expanded, so the
                  expanded button sat on a permanent grey block. A row-wide
                  toggle is not a button you want shouting — neutralise both
                  fills and let the text colour carry the interaction. */}
              <Button
                variant="ghost"
                size="sm"
                // aria-expanded beats hover on equal specificity, so the
                // expanded state needs the compound variant or hovering it
                // does nothing.
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
        open={killing !== null}
        onOpenChange={(next) => !next && setKilling(null)}
        icon={Square}
        tone="destructive"
        title={t("killTitle")}
        description={t("killDescription")}
        cancelLabel={t("cancel")}
        confirmLabel={
          pending ? (
            <>
              <Loader2 className="size-4 animate-spin" />
              {t("killing")}
            </>
          ) : (
            t("stopQuery")
          )
        }
        pending={pending}
        onConfirm={kill}
      />
    </>
  );
}
