import { useTranslations, useFormatter } from "next-intl";
import { Activity } from "lucide-react";
import { clockFormatter } from "@/lib/format/time";
import { LiveChartCard } from "@/components/dashboard/live-chart-card";
import { EChart, useChartTokens } from "@/components/ui/echart";
import {
  axisMax,
  seriesDataTable,
  timeSeriesOption,
} from "@/lib/charts/time-series-option";

/**
 * 5- and 15-minute load against the core count.
 *
 * The cores line is the whole point: a load of 3.2 is idle on 8 cores and a
 * crisis on 2, so the number means nothing without the capacity drawn beside
 * it. The axis is therefore pinned to include cores even when load is near
 * zero — the lines hugging the floor under a high ceiling *is* the message.
 *
 * No 1-minute average. It is the noisiest of the three and says nothing the
 * 5-minute line doesn't say more reliably; the stat card above still prints
 * all three as numbers for anyone who wants the instant reading.
 */
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
  // No seconds: samples are five minutes apart, so a clock reading 11:05:00
  // implies a precision the data does not have.
  const clock = clockFormatter(format, timeZone);
  const decimal = (value) => format.number(Number(value), { maximumFractionDigits: 2 });
  // Core count is a fact about the machine, not a sample — the live poll is
  // still the honest source for it even on a history chart.
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
        max: axisMax(history, ["load_5", "load_15"], { floor: cores * 1.05 }),
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
      emptyMessage={t("charts.noHistory")}
      // The newest COLLECTED sample, not the live poll: this card is the last
      // day, and a header number from a different clock than the line under it
      // is how you get someone reading the two against each other.
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
