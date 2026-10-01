import { useTranslations, useFormatter } from "next-intl";
import { HardDrive } from "lucide-react";
import { formatRate } from "@/lib/format/bytes";
import { clockFormatter } from "@/lib/format/time";
import { ChartPill, LiveChartCard } from "@/components/dashboard/live-chart-card";
import { EChart, useChartTokens } from "@/components/ui/echart";
import {
  axisMax,
  seriesDataTable,
  timeSeriesOption,
} from "@/lib/charts/time-series-option";

// Op counts (IOPS) sit in the header rather than on a second axis.
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

export function DiskIoChart({ series: chartSeries, metrics, timeZone, stale }) {
  const t = useTranslations("serverDashboard");
  const format = useFormatter();
  const rate = (value) => formatRate(value, format);
  const clock = clockFormatter(format, timeZone, { second: "2-digit" });
  const ops = (value) => format.number(Number(value ?? 0));
  const tokens = useChartTokens(TOKENS);

  // Same colours as the network card: down is chart-2, up is chart-1.
  const series = [
    { key: "disk_read", label: t("charts.disk.read"), token: "chart-2", kind: "area" },
    { key: "disk_write", label: t("charts.disk.write"), token: "chart-1", kind: "area" },
  ];

  const option = timeSeriesOption({
    data: chartSeries,
    series,
    tokens,
    xLabel: clock,
    value: rate,
    axes: [
      {
        max: axisMax(chartSeries, ["disk_read", "disk_write"], { floor: 65536 }),
        formatter: rate,
      },
    ],
  });
  const table = seriesDataTable({
    caption: t("charts.disk.title"),
    timeLabel: t("charts.time"),
    data: chartSeries,
    series,
    xLabel: clock,
    value: rate,
  });

  return (
    <LiveChartCard
      icon={HardDrive}
      title={t("charts.disk.title")}
      description={t("charts.disk.description")}
      ready={chartSeries.length >= 2}
      stale={stale}
      badges={
        <>
          {/* The op count rides along as the pill's `note`. */}
          <ChartPill
            dotClassName="bg-chart-2"
            label={t("charts.disk.read")}
            value={rate(metrics?.disk_io?.read)}
            note={t("charts.disk.iops", { ops: ops(metrics?.disk_io?.read_ops) })}
          />
          <ChartPill
            dotClassName="bg-chart-1"
            label={t("charts.disk.write")}
            value={rate(metrics?.disk_io?.write)}
            note={t("charts.disk.iops", { ops: ops(metrics?.disk_io?.write_ops) })}
          />
        </>
      }
    >
      <EChart option={option} dataTable={table} height="h-72" />
    </LiveChartCard>
  );
}
