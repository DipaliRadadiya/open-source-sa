import { useTranslations, useFormatter } from "next-intl";
import { Gauge } from "lucide-react";
import { clockFormatter } from "@/lib/format/time";
import { ChartPill, LiveChartCard, SWATCH } from "@/components/dashboard/live-chart-card";
import { EChart, useChartTokens } from "@/components/ui/echart";
import {
  seriesDataTable,
  timeSeriesOption,
} from "@/lib/charts/time-series-option";

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
  const clock = clockFormatter(format, timeZone);
  const percentTick = (value) =>
    format.number(Number(value) / 100, { style: "percent", maximumFractionDigits: 0 });
  const percentValue = (value) =>
    format.number(Number(value) / 100, { style: "percent", maximumFractionDigits: 1 });

  // A collector that can't read the disk stores 0, not null, which would look like an empty disk.
  const hasDisk = history.some((point) => Number(point.disk) > 0);
  const latest = history.at(-1);
  const series = [
    { key: "cpu", label: t("cpu"), token: "chart-1", kind: "area" },
    { key: "memory", label: t("memory"), token: "chart-2", kind: "area" },
    ...(hasDisk ? [{ key: "disk", label: t("disk"), token: "chart-3", kind: "area" }] : []),
  ];

  const option = timeSeriesOption({
    legend: false,
    data: history,
    series,
    tokens,
    xLabel: clock,
    value: percentValue,
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
      ready={history.length >= 2}
      badges={series.map((s) => (
        <ChartPill key={s.key} dotClassName={SWATCH[s.token]} label={s.label} />
      ))}
      compactEmpty
      emptyTitle={t("charts.noHistoryTitle")}
      emptyMessage={t("charts.noHistory")}
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
