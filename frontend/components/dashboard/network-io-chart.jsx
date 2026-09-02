import { useTranslations, useFormatter } from "next-intl";
import { ArrowDownUp } from "lucide-react";
import { formatRate } from "@/lib/format/bytes";
import { clockFormatter } from "@/lib/format/time";
import { Badge } from "@/components/ui/badge";
import { LiveChartCard } from "@/components/dashboard/live-chart-card";
import { EChart, useChartTokens } from "@/components/ui/echart";
import {
  axisMax,
  seriesDataTable,
  timeSeriesOption,
} from "@/lib/charts/time-series-option";


/** Tokens these charts draw with, resolved from globals.css at runtime. */
const TOKENS = ["chart-1", "chart-2", "chart-3", "chart-4", "border", "muted-foreground", "popover", "popover-foreground"];

export function NetworkIoChart({ series, metrics, timeZone, stale }) {
  const t = useTranslations("serverDashboard");
  const format = useFormatter();
  const tokens = useChartTokens(TOKENS);
  const rate = (value) => formatRate(value, format);
  const clock = clockFormatter(format, timeZone, { second: "2-digit" });

  const lines = [
    { key: "net_in", label: t("charts.network.in"), token: "chart-2", kind: "area" },
    { key: "net_out", label: t("charts.network.out"), token: "chart-1", kind: "area" },
  ];

  const option = timeSeriesOption({
    data: series,
    series: lines,
    tokens,
    xLabel: clock,
    value: rate,
    // Floored at 64 KB/s so idle traffic does not render as dramatic spikes
    // on an auto-scaled axis.
    axes: [{ max: axisMax(series, ["net_in", "net_out"], { floor: 65536 }), formatter: rate }],
  });

  const table = seriesDataTable({
    caption: t("charts.network.title"),
    timeLabel: t("charts.time"),
    data: series,
    series: lines,
    xLabel: clock,
    value: rate,
  });

  return (
    <LiveChartCard
      icon={ArrowDownUp}
      title={t("charts.network.title")}
      description={t("charts.network.description")}
      ready={series.length >= 2}
      stale={stale}
      badges={
        <>
          <Badge
            variant="outline"
            className="gap-1 border-chart-2/30 bg-chart-2/10 font-medium tabular-nums text-chart-2"
          >
            ↓ {rate(metrics?.network?.in)}
          </Badge>
          <Badge
            variant="outline"
            className="gap-1 border-chart-1/30 bg-chart-1/10 font-medium tabular-nums text-chart-1"
          >
            ↑ {rate(metrics?.network?.out)}
          </Badge>
        </>
      }
    >
      <EChart option={option} dataTable={table} height="h-72" />
    </LiveChartCard>
  );
}
