import { useTranslations, useFormatter } from "next-intl";
import { Gauge } from "lucide-react";
import { clockFormatter } from "@/lib/format/time";
import { LiveChartCard } from "@/components/dashboard/live-chart-card";
import { EChart, useChartTokens } from "@/components/ui/echart";
import {
  seriesDataTable,
  timeSeriesOption,
} from "@/lib/charts/time-series-option";

// Load is a queue depth, not a percentage, so it has its own card.
/** Resolved from globals.css at runtime; never restated as literals here. */
const TOKENS = [
  "chart-1",
  "chart-2",
  "chart-3",
  "chart-4",
  "border",
  "muted-foreground",
  "popover",
  "popover-foreground",
];

export function ResourceUsageChart({ history = [], timeZone }) {
  const t = useTranslations("serverDashboard");
  const format = useFormatter();
  const tokens = useChartTokens(TOKENS);
  // No seconds — five minutes between samples.
  const clock = clockFormatter(format, timeZone);
  const percentTick = (value) =>
    format.number(Number(value) / 100, { style: "percent", maximumFractionDigits: 0 });
  const percentValue = (value) =>
    format.number(Number(value) / 100, { style: "percent", maximumFractionDigits: 1 });

  // Disk joins only when measured: a collector that cannot read the filesystem
  // stores 0, not null, and a flat 0 % line would read as an empty disk.
  const hasDisk = history.some((point) => Number(point.disk) > 0);
  const latest = history.at(-1);
  const series = [
    { key: "cpu", label: t("cpu"), token: "chart-1", kind: "area" },
    { key: "memory", label: t("memory"), token: "chart-2", kind: "area" },
    ...(hasDisk ? [{ key: "disk", label: t("disk"), token: "chart-4", kind: "area" }] : []),
  ];

  const option = timeSeriesOption({
    data: history,
    series,
    tokens,
    xLabel: clock,
    value: percentValue,
    // Fixed 0-100 with explicit ticks; an auto axis lands on -2%.
    axes: [{ max: 100, interval: 25, formatter: percentTick }],
  });
  const table = seriesDataTable({
    caption: t("charts.usage.title"),
    timeLabel: t("charts.time"),
    data: history,
    series,
    xLabel: clock,
    value: percentValue,
  });

  return (
    <LiveChartCard
      icon={Gauge}
      title={t("charts.usage.title")}
      description={t("charts.usage.description")}
      ready={history.length >= 2}
      compactEmpty
      emptyTitle={t("charts.noHistoryTitle")}
      emptyMessage={t("charts.noHistory")}
      // Read off the newest collected sample so the header and the line agree.
      summary={
        latest
          ? `${t("cpu")} ${percentValue(latest.cpu)} · ${t("memory")} ${percentValue(latest.memory)}`
          : null
      }
    >
      <EChart option={option} dataTable={table} height="h-72" />
    </LiveChartCard>
  );
}
