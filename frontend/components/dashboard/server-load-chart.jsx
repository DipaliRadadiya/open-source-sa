import { useTranslations, useFormatter } from "next-intl";
import { Activity } from "lucide-react";
import { clockFormatter } from "@/lib/format/time";
import { ChartPill, LiveChartCard, SWATCH } from "@/components/dashboard/live-chart-card";
import { EChart, useChartTokens } from "@/components/ui/echart";
import {
  axisMax,
  niceCeiling,
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

export function ServerLoadChart({ history = [], metrics, timeZone }) {
  const t = useTranslations("serverDashboard");
  const format = useFormatter();
  const tokens = useChartTokens(TOKENS);
  const clock = clockFormatter(format, timeZone);
  const decimal = (value) => format.number(Number(value), { maximumFractionDigits: 2 });
  const cores = Number(metrics?.cpu?.cores) || 0;
  const latest = history.at(-1);

  const series = [
    { key: "load_5", label: "5m", token: "chart-1", kind: "line" },
    { key: "load_15", label: "15m", token: "chart-2", kind: "line" },
  ];

  const option = timeSeriesOption({
    legend: false,
    data: history,
    series,
    tokens,
    xLabel: clock,
    value: decimal,
    axes: [
      {
        max: axisMax(history, ["load_5", "load_15"], { floor: niceCeiling(cores * 1.05) }),
        formatter: decimal,
      },
    ],
    markLine: cores
      ? { value: cores, label: t("charts.load.cores", { count: cores }), token: "chart-3" }
      : null,
  });
  const table = seriesDataTable({
    caption: t("charts.load.title"),
    timeLabel: t("charts.time"),
    data: history,
    series,
    xLabel: clock,
    value: decimal,
  });

  return (
    <LiveChartCard
      icon={Activity}
      title={t("charts.load.title")}
      ready={history.length >= 2}
      badges={
        <>
          {series.map((s) => (
            <ChartPill key={s.key} dotClassName={SWATCH[s.token]} label={s.label} />
          ))}
          {cores ? <ChartPill dotClassName={SWATCH["chart-3"]} label={t("charts.load.cores", { count: cores })} /> : null}
        </>
      }
      compactEmpty
      emptyTitle={t("charts.noHistoryTitle")}
      emptyMessage={t("charts.noHistory")}
      summary={
        latest
          ? `5m ${decimal(latest.load_5)} · 15m ${decimal(latest.load_15)}`
          : null
      }
    >
      <EChart option={option} dataTable={table} height="h-72" />
    </LiveChartCard>
  );
}
