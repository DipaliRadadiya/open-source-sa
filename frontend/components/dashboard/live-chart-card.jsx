import { HeadIcon } from "@/components/ui/head-icon";
import { useTranslations } from "next-intl";
import { ChartSpline } from "lucide-react";
import { cn } from "@/lib/utils";
import { PANEL_CARD } from "@/lib/theme/card-chrome";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";

export const SWATCH = {
  "chart-1": "bg-chart-1",
  "chart-2": "bg-chart-2",
  "chart-3": "bg-chart-3",
  "chart-4": "bg-chart-4",
  "chart-5": "bg-chart-5",
};

export function ChartPill({ dotClassName, label, value, note }) {
  return (
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
  icon = null,
  headingLevel = "h3",
  plotHeight = "h-72",
  title,
  badges,
  summary,
  ready,
  stale = false,
  emptyTitle,
  emptyMessage,
  compactEmpty = false,
  children,
}) {
  const t = useTranslations("serverDashboard");

  return (
    <Card
      className={cn(
        "h-full gap-0 transition-opacity [--card-spacing:--spacing(4)]",
        PANEL_CARD,
        stale && "opacity-60",
      )}
    >
      <CardHeader className="flex flex-col items-stretch gap-1 space-y-0 border-b">
        <div className="min-w-0">
          <CardTitle
            as={headingLevel}
            className="flex flex-wrap items-center justify-between gap-x-2.5 gap-y-2"
          >
            <span className="flex shrink-0 items-center gap-3">
              <HeadIcon icon={icon} />
              <span className="whitespace-nowrap">{title}</span>
            </span>
            {badges ? <span className="flex flex-wrap gap-2">{badges}</span> : null}
          </CardTitle>
        </div>
      </CardHeader>
      <CardContent className="mt-auto pt-4">
        {ready ? (
          children
        ) : compactEmpty ? (
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
