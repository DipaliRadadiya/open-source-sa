import { useTranslations, useFormatter } from "next-intl";
import { Activity } from "lucide-react";
import { clockFormatter } from "@/lib/format/time";
import { LiveChartCard } from "@/components/dashboard/live-chart-card";
import { EChart, useChartTokens } from "@/components/ui/echart";
import {
  axisMax,
  niceCeiling,
  seriesDataTable,
  timeSeriesOption,
} from "@/lib/charts/time-series-option";

// 5- and 15-minute load against the core count; the axis always includes the cores line.
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

export function ServerLoadChart({ history = [], metrics, timeZone }) {
  const t = useTranslations("serverDashboard");
  const format = useFormatter();
  const tokens = useChartTokens(TOKENS);
  // No seconds: samples are five minutes apart.
  const clock = clockFormatter(format, timeZone);
  const decimal = (value) => format.number(Number(value), { maximumFractionDigits: 2 });
  // Core count is a fact about the machine, so the live poll is its source.
  const cores = Number(metrics?.cpu?.cores) || 0;
  const latest = history.at(-1);

  const series = [
    { key: "load_5", label: "5m", token: "chart-1", kind: "line" },
    { key: "load_15", label: "15m", token: "chart-2", kind: "line" },
  ];

  const option = timeSeriesOption({
    data: history,
    series,
    tokens,
    xLabel: clock,
    value: decimal,
    // The axis must include the core count even when load is near zero: the
    // lines hugging the floor under a high ceiling is the message.
    axes: [
      {
        // Rounded, because a forced ceiling gets a label of its own: the raw
        // `cores * 1.05` printed "4.2" hard against the "4" tick below it.
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
      description={t("charts.load.description")}
      ready={history.length >= 2}
      compactEmpty
      emptyTitle={t("charts.noHistoryTitle")}
      emptyMessage={t("charts.noHistory")}
      // The newest COLLECTED sample, not the live poll, so header and line agree.
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
