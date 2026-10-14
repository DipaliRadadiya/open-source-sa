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

// Literal classes for Tailwind; a series token maps to its swatch.
export const SWATCH = {
  "chart-1": "bg-chart-1",
  "chart-2": "bg-chart-2",
  "chart-3": "bg-chart-3",
  "chart-4": "bg-chart-4",
  "chart-5": "bg-chart-5",
};

// The dot maps the pill to its line; the value stays at full contrast.
export function ChartPill({ dotClassName, label, value, note }) {
  return (
    /* Tighter label–value gap than to the note: "Read 4.5 MB/s" is one reading, "210 IOPS" a second. */
    // Steel UI: a square mono chip with a square swatch (prototype).
    <span className="inline-flex items-center gap-2 rounded-[2px] border bg-muted/40 px-2 py-1 font-mono text-xs tracking-[0.04em]">
      <span className={cn("size-2 shrink-0", dotClassName)} />
      <span className="flex items-center gap-1.5">
        <span className="text-muted-foreground uppercase">{label}</span>
        {value !== undefined ? <span className="font-medium tabular-nums text-foreground">{value}</span> : null}
      </span>
      {note ? <span className="tabular-nums text-muted-foreground">{note}</span> : null}
    </span>
  );
}

export function LiveChartCard({
  // h3 inside the "Last 24 hours" section; the live pair stands alone, so h2.
  headingLevel = "h3",
  // The live pair is shorter than the 24h charts (the redesign); the wait matches it.
  plotHeight = "h-72",
  title,
  description,
  badges,
  summary,
  ready,
  stale = false,
  // Live charts wait seconds for a sample; 24h charts wait on a five-minute collector.
  emptyTitle,
  emptyMessage,
  /* Live cards keep full height while empty (filling in would jump); 24h cards may never get a sample. */
  compactEmpty = false,
  children,
}) {
  const t = useTranslations("serverDashboard");

  return (
    // Dimmed on a dead poll: a frozen feed draws a flat line that looks like a calm server.
    <Card
      className={cn(
        "h-full gap-0 transition-opacity [--card-spacing:--spacing(4)]",
        PANEL_CARD,
        stale && "opacity-60",
      )}
    >
      {/* The plot is bottom-anchored so paired charts start on the same line. */}
      {/* Title and readings over a hairline; the description opens the body (prototype). */}
      <CardHeader className="flex flex-col items-stretch gap-1 space-y-0 border-b">
        <div className="min-w-0">
          {/* h3, not h2: these cards sit inside a section whose heading is the h2. */}
          {/* justify-between, not ml-auto: a wrapped pill group lands at the start. No width breakpoint: widths vary by locale. */}
          <CardTitle
            as={headingLevel}
            className="flex flex-wrap items-center justify-between gap-x-2.5 gap-y-2"
          >
            <span className="flex shrink-0 items-center gap-2.5">
              {/* nowrap: otherwise the heading is the only shrinkable item beside shrink-0 pills. */}
              <span className="whitespace-nowrap">{title}</span>
            </span>
            {badges ? <span className="flex flex-wrap gap-2">{badges}</span> : null}
          </CardTitle>
        </div>
      </CardHeader>
      {/* Two lines reserved so paired cards align when descriptions wrap differently. */}
      <CardDescription className="min-h-10 px-(--card-spacing) pt-4">{description}</CardDescription>
      {/* mt-auto aligns paired charts. */}
      <CardContent className="mt-auto pt-2">
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
          // Same height as the plot so nothing jumps. A faint chart shape rather than a blank
          // box, which read as broken for the first seconds (Krishna, 13 Oct).
          <div className={cn("relative flex flex-col items-center justify-center gap-2 px-6 text-center", plotHeight)}>
            <svg
              aria-hidden
              viewBox="0 0 400 120"
              preserveAspectRatio="none"
              className="absolute inset-x-2 inset-y-4 h-[calc(100%-2rem)] w-[calc(100%-1rem)] text-muted-foreground/15"
            >
              {[10, 45, 80, 115].map((y) => (
                <line key={y} x1="0" x2="400" y1={y} y2={y} stroke="currentColor" strokeWidth="1" vectorEffect="non-scaling-stroke" />
              ))}
              <path
                d="M0 92 C40 88 60 70 100 74 S160 96 200 82 S260 52 300 60 S360 84 400 70"
                fill="none"
                stroke="currentColor"
                strokeWidth="2.5"
                vectorEffect="non-scaling-stroke"
                className="animate-pulse text-muted-foreground/30 motion-reduce:animate-none"
              />
            </svg>
            {summary ? (
              <p className="relative text-lg font-semibold tabular-nums">{summary}</p>
            ) : null}
            <p className="relative rounded-md bg-card/80 px-2 text-sm text-muted-foreground">
              {emptyMessage ?? t("charts.waiting")}
            </p>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
