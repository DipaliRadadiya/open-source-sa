"use client";

import { useTranslations, useFormatter } from "next-intl";
import { HardDrive } from "lucide-react";
import { Area, AreaChart, CartesianGrid, XAxis, YAxis } from "recharts";
import { formatRate } from "@/lib/format/bytes";
import { clockFormatter } from "@/lib/format/time";
import { Badge } from "@/components/ui/badge";
import {
  LiveChartCard,
  orderedLegend,
  timeLabel,
} from "@/components/server-dashboard/live-chart-card";
import {
  ChartContainer,
  ChartTooltip,
  ChartTooltipContent,
  ChartLegend,
} from "@/components/ui/chart";

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
export function DiskIoChart({ series, metrics, timeZone, stale }) {
  const t = useTranslations("serverDashboard");
  const format = useFormatter();
  const rate = (value) => formatRate(value, format);
  const clock = clockFormatter(format, timeZone, { second: "2-digit" });
  const ops = (value) => format.number(Number(value ?? 0));

  // Same colours as the network card — down is chart-2, up is chart-1 — so the
  // two I/O charts can be read with one set of eyes.
  const config = {
    disk_read: { label: t("charts.disk.read"), color: "var(--chart-2)" },
    disk_write: { label: t("charts.disk.write"), color: "var(--chart-1)" },
  };

  return (
    <LiveChartCard
      icon={HardDrive}
      title={t("charts.disk.title")}
      description={t("charts.disk.description")}
      ready={series.length >= 2}
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
      <ChartContainer config={config} className="h-72 w-full">
        <AreaChart data={series} margin={{ left: -10, right: 8 }}>
          <defs>
            {Object.entries(config).map(([key, cfg]) => (
              <linearGradient key={key} id={`dio-${key}`} x1="0" y1="0" x2="0" y2="1">
                <stop offset="5%" stopColor={cfg.color} stopOpacity={0.35} />
                <stop offset="95%" stopColor={cfg.color} stopOpacity={0.03} />
              </linearGradient>
            ))}
          </defs>
          <CartesianGrid vertical={false} strokeDasharray="3 3" />
          <XAxis
            dataKey="t"
            tickLine={false}
            axisLine={false}
            minTickGap={48}
            tickFormatter={clock}
          />
          <YAxis
            tickLine={false}
            axisLine={false}
            width={70}
            domain={[0, (max) => Math.max(65536, max * 1.2)]}
            tickFormatter={rate}
          />
          <ChartTooltip
            content={
              <ChartTooltipContent
                indicator="line"
                labelFormatter={timeLabel(clock)}
                valueFormatter={(v) => rate(v)}
              />
            }
          />
          <ChartLegend content={orderedLegend(config)} />
          {Object.keys(config).map((key) => (
            <Area
              key={key}
              type="monotone"
              dataKey={key}
              stroke={config[key].color}
              fill={`url(#dio-${key})`}
              strokeWidth={2}
              dot={false}
              isAnimationActive={false}
            />
          ))}
        </AreaChart>
      </ChartContainer>
    </LiveChartCard>
  );
}
