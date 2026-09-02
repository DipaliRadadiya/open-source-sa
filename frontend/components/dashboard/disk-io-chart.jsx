import { useTranslations, useFormatter } from "next-intl";
import { HardDrive } from "lucide-react";
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

/**
 * Read/write throughput, with the op counts in the header rather than on the
 * plot. They answer different questions — throughput says "saturated", IOPS
 * says "thrashing", and a disk can be pinned at 100% busy while moving very
 * few megabytes — but they are different units and would need a second axis
 * nobody reads correctly.
 *
 * Deliberately its own card, not a footnote under the network chart: while it
 * lived there, "Disk" meant I/O in one place and free space in the stat card
 * above, which is two meanings for one word on one screen.
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

export function DiskIoChart({ series: chartSeries, metrics, timeZone, stale }) {
  const t = useTranslations("serverDashboard");
  const format = useFormatter();
  const rate = (value) => formatRate(value, format);
  const clock = clockFormatter(format, timeZone, { second: "2-digit" });
  const ops = (value) => format.number(Number(value ?? 0));
  const tokens = useChartTokens(TOKENS);

  // Same colours as the network card — down is chart-2, up is chart-1 — so the
  // two I/O charts can be read with one set of eyes.
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
          <Badge
            variant="outline"
            className="gap-1 border-chart-2/30 bg-chart-2/10 font-medium tabular-nums text-chart-2"
          >
            ↓ {rate(metrics?.disk_io?.read)}
            <span className="text-[10px] opacity-80">
              {t("charts.disk.iops", { ops: ops(metrics?.disk_io?.read_ops) })}
            </span>
          </Badge>
          <Badge
            variant="outline"
            className="gap-1 border-chart-1/30 bg-chart-1/10 font-medium tabular-nums text-chart-1"
          >
            ↑ {rate(metrics?.disk_io?.write)}
            <span className="text-[10px] opacity-80">
              {t("charts.disk.iops", { ops: ops(metrics?.disk_io?.write_ops) })}
            </span>
          </Badge>
        </>
      }
    >
      <EChart option={option} dataTable={table} height="h-72" />
    </LiveChartCard>
  );
}
