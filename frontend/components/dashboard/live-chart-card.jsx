import { useTranslations } from "next-intl";
import { ChartSpline } from "lucide-react";
import { cn } from "@/lib/utils";
import { PANEL_CARD } from "@/lib/theme/card-chrome";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  CardDescription,
} from "@/components/ui/card";

/**
 * Shared shell for the four live charts in a 2x2 grid, so header shape, chart
 * height and the empty state stay identical. `badges` holds the current
 * reading in the header.
 */
/**
 * One current reading, shared by both I/O cards: a coloured dot maps the pill
 * to its line, the series name says which line, and the value stays at full
 * foreground contrast.
 */
export function ChartPill({ dotClassName, label, value, note }) {
  return (
    /*
     * The label–value gap is tighter than the gap to the note: "Read 4.5 MB/s"
     * is one reading, "210 IOPS" a second.
     */
    <span className="inline-flex items-center gap-2 rounded-lg border bg-muted/40 px-2.5 py-1.5 text-xs">
      <span className={cn("size-2 shrink-0 rounded-full", dotClassName)} />
      <span className="flex items-center gap-1.5">
        <span className="text-muted-foreground">{label}</span>
        <span className="font-medium tabular-nums text-foreground">{value}</span>
      </span>
      {note ? <span className="tabular-nums text-muted-foreground">{note}</span> : null}
    </span>
  );
}

export function LiveChartCard({
  icon: Icon,
  title,
  description,
  badges,
  summary,
  ready,
  stale = false,
  // Live charts wait seconds for a second sample; 24h charts wait on a
  // five-minute collector, so they need different empty copy.
  emptyTitle,
  emptyMessage,
  /*
   * Live cards keep the full plot height while empty (they fill in after two
   * polls, so collapsing would jump). 24h cards may never get a sample, so
   * they use a compact empty state.
   */
  compactEmpty = false,
  children,
}) {
  const t = useTranslations("serverDashboard");

  return (
    // Dimmed on a dead poll like the stat cards; a frozen feed would otherwise
    // draw a flat line that looks like a calm server.
    // Ring and shadow come from PANEL_CARD so every card on this page matches.
    <Card
      className={cn(
        "h-full transition-opacity [--card-spacing:--spacing(5)]",
        PANEL_CARD,
        stale && "opacity-60",
      )}
    >
      {/*
       * Pills share the title line when there is room; otherwise they take
       * their own line rather than squeezing the heading. The plot is
       * bottom-anchored so paired charts still start on the same line.
       */}
      <CardHeader className="flex flex-col items-stretch gap-1 space-y-0">
        <div className="min-w-0 space-y-1">
          {/* h3, not h2: these cards sit inside a section whose heading is the h2. */}
          {/*
           * justify-between, not ml-auto: justify-content applies per line, so
           * a wrapped pill group lands at the start under the heading instead
           * of staying pinned right. No width breakpoint: widths vary too much
           * by locale.
           */}
          <CardTitle
            as="h3"
            className="flex flex-wrap items-center justify-between gap-x-2.5 gap-y-2 text-lg font-semibold"
          >
            <span className="flex shrink-0 items-center gap-2.5">
              <span className="flex shrink-0 items-center justify-center text-muted-foreground">
                <Icon className="size-4" />
              </span>
              {/* nowrap: as a bare text node the heading was the only shrinkable
                  flex item beside the shrink-0 pills, so it broke first. */}
              <span className="whitespace-nowrap">{title}</span>
            </span>
            {badges ? <span className="flex flex-wrap gap-2">{badges}</span> : null}
          </CardTitle>
          {/* Two lines reserved so paired cards align when descriptions wrap differently. */}
          <CardDescription className="min-h-10">{description}</CardDescription>
        </div>
      </CardHeader>
      {/* pt-0: Card already adds --card-spacing between header and content.
          mt-auto anchors the plot to the card's bottom, so paired charts
          start on the same line whatever their headers do. */}
      <CardContent className="mt-auto pt-0">
        {/* A line needs two points; until then say so. */}
        {ready ? (
          children
        ) : compactEmpty ? (
          /* Solid, not dashed: a dashed box reads as an unfinished placeholder. */
          <div className="flex h-[130px] flex-col items-center justify-center gap-1 rounded-lg bg-muted/40 px-6 text-center">
            <span className="mb-1 flex size-8 items-center justify-center rounded-full bg-background text-muted-foreground shadow-e1">
              <ChartSpline className="size-4" />
            </span>
            <p className="text-sm font-medium">{emptyTitle ?? t("charts.noHistoryTitle")}</p>
            <p className="max-w-md text-xs text-pretty text-muted-foreground">
              {emptyMessage ?? t("charts.noHistory")}
            </p>
          </div>
        ) : (
          // Same height as the plot so nothing jumps, and it shows the current reading.
          <div className="flex h-72 flex-col items-center justify-center gap-2 px-6 text-center">
            {summary ? (
              <p className="text-lg font-semibold tabular-nums">{summary}</p>
            ) : null}
            <p className="text-sm text-muted-foreground">
              {emptyMessage ?? t("charts.waiting")}
            </p>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
