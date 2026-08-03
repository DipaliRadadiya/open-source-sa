"use client";

import { useTranslations, useFormatter } from "next-intl";
import { Activity, HardDrive } from "lucide-react";
import { formatRate } from "@/lib/format/bytes";
import { clockFormatter } from "@/lib/format/time";
import { Area, AreaChart, CartesianGrid, XAxis, YAxis } from "recharts";
import { Badge } from "@/components/ui/badge";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  CardDescription,
} from "@/components/ui/card";
import {
  ChartContainer,
  ChartTooltip,
  ChartTooltipContent,
  ChartLegend,
  ChartLegendContent,
} from "@/components/ui/chart";

export function NetworkChart({ series, metrics, timeZone }) {
  const t = useTranslations("serverDashboard");
  const format = useFormatter();
  const rate = (value) => formatRate(value, format);
  const clock = clockFormatter(format, timeZone, { second: "2-digit" });

  const config = {
    in: { label: t("network.in"), color: "var(--chart-2)" },
    out: { label: t("network.out"), color: "var(--chart-1)" },
  };

  return (
    <Card className="h-full">
      <CardHeader className="flex flex-row items-start justify-between gap-4 space-y-0">
        <div className="space-y-1">
          <CardTitle className="flex items-center gap-2 text-lg font-semibold">
            <Activity className="size-4 text-primary" />
            {t("throughput.title")}
          </CardTitle>
          <CardDescription>{t("throughput.description")}</CardDescription>
        </div>
        <div className="flex shrink-0 gap-1.5">
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
        </div>
      </CardHeader>
      <CardContent className="pt-3">
        {series.length < 2 ? (
          <p className="flex h-72 items-center justify-center text-sm text-muted-foreground">
            {t("network.waiting")}
          </p>
        ) : (
          <ChartContainer config={config} className="h-72 w-full">
            <AreaChart data={series} margin={{ left: -10, right: 8 }}>
              <defs>
                {Object.entries(config).map(([key, cfg]) => (
                  <linearGradient key={key} id={`net-${key}`} x1="0" y1="0" x2="0" y2="1">
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
              {/* Floor the scale at 64 KB/s so idle traffic doesn't render as
                  dramatic spikes on an auto-scaled axis. */}
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
                    labelFormatter={clock}
                    formatter={(v) => rate(v)}
                  />
                }
              />
              <ChartLegend content={<ChartLegendContent />} />
              {Object.keys(config).map((key) => (
                <Area
                  key={key}
                  type="monotone"
                  dataKey={key}
                  stroke={config[key].color}
                  fill={`url(#net-${key})`}
                  strokeWidth={2}
                  dot={false}
                  isAnimationActive={false}
                />
              ))}
            </AreaChart>
          </ChartContainer>
        )}

        {/* Disk gets a row, not a second chart: it is the same unit as the
            network series but orders of magnitude apart, so plotting both on
            one axis would flatten whichever is quieter. The op counts sit
            beside the rates because they answer a different question —
            throughput says "saturated", IOPS says "thrashing", and a disk can
            be pinned at 100% busy while moving very few megabytes. */}
        {metrics?.disk_io ? (
          <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1.5 border-t pt-3 text-sm">
            <span className="flex items-center gap-1.5 font-medium text-muted-foreground">
              <HardDrive className="size-3.5" />
              {t("throughput.disk")}
            </span>
            <span className="tabular-nums">
              ↓ {rate(metrics.disk_io.read)}
              <span className="ml-1 text-xs text-muted-foreground">
                {t("throughput.iops", { ops: metrics.disk_io.read_ops ?? 0 })}
              </span>
            </span>
            <span className="tabular-nums">
              ↑ {rate(metrics.disk_io.write)}
              <span className="ml-1 text-xs text-muted-foreground">
                {t("throughput.iops", { ops: metrics.disk_io.write_ops ?? 0 })}
              </span>
            </span>
          </div>
        ) : null}
      </CardContent>
    </Card>
  );
}
