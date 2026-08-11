"use client";

import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  CardDescription,
} from "@/components/ui/card";
import { ChartLegendContent } from "@/components/ui/chart";

/**
 * Recharts builds the legend from its own item ordering, not the order the
 * series are declared, so load average listed itself as "1m · 15m · 5m" —
 * against the order the stat card above it uses. Passing `payload` to Legend
 * does not help (the chart clones the element and overwrites it), so the
 * ordering has to happen inside the content callback.
 */
export function orderedLegend(config) {
  const order = Object.keys(config);
  return function OrderedLegend(props) {
    const payload = [...(props.payload ?? [])].sort(
      (a, b) => order.indexOf(a.dataKey) - order.indexOf(b.dataKey),
    );
    return <ChartLegendContent {...props} payload={payload} />;
  };
}

/**
 * ChartTooltipContent only trusts the x value when it is a string; ours is a
 * timestamp number, so it falls back to the first series' label and the header
 * ends up formatting "5m" as a clock. Read the timestamp off the payload.
 */
export function timeLabel(clock) {
  return function formatTimeLabel(_, payload) {
    return clock(payload?.[0]?.payload?.t);
  };
}

/**
 * Shared shell for the four live charts. They sit in a 2x2 grid, so the header
 * shape, chart height and "no samples yet" state have to be identical or the
 * grid reads as four unrelated cards.
 *
 * `badges` is the current reading — the number you came for — kept in the
 * header where it is legible, because reading a value off a moving line is
 * something nobody should have to do.
 */
export function LiveChartCard({
  icon: Icon,
  title,
  description,
  badges,
  summary,
  ready,
  stale = false,
  // The live charts are waiting seconds for their second sample; the 24h ones
  // are waiting on a collector that runs every five minutes. "Waiting for
  // samples…" under a chart that will stay empty for the next five minutes
  // reads as broken.
  emptyMessage,
  children,
}) {
  const t = useTranslations("serverDashboard");

  return (
    // Dimmed on a dead poll, exactly like the stat cards. Without it a frozen
    // feed draws a perfectly flat line, which reads as a calm server rather
    // than as no server at all.
    <Card className={cn("h-full transition-opacity", stale && "opacity-60")}>
      <CardHeader className="flex flex-row items-start justify-between gap-4 space-y-0">
        <div className="space-y-1">
          <CardTitle className="flex items-center gap-2 text-lg font-semibold">
            <Icon className="size-4 text-primary" />
            {title}
          </CardTitle>
          <CardDescription>{description}</CardDescription>
        </div>
        {badges ? <div className="flex shrink-0 gap-1.5">{badges}</div> : null}
      </CardHeader>
      <CardContent className="pt-3">
        {/* A line needs two points. Until then say so, rather than drawing a
            single dot and calling it a trend. */}
        {ready ? (
          children
        ) : (
          // Same height as the plot so nothing jumps when the line appears —
          // and it carries the current reading, because a card that says only
          // "waiting" is a card doing nothing for the first few seconds of
          // every single page load.
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
