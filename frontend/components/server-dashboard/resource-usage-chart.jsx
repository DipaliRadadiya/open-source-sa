"use client";

import { useTranslations, useFormatter } from "next-intl";
import { Gauge } from "lucide-react";
import { Area, AreaChart, CartesianGrid, XAxis, YAxis } from "recharts";
import { clockFormatter } from "@/lib/format/time";
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
 * Three percentages on one 0-100 axis.
 *
 * ServerAvatar's own "Resource Usage" plots memory, disk and *load* together,
 * and their docs then have to warn people not to confuse it with the Server
 * Load Monitor. Load is a queue depth, not a percentage — on a shared axis it
 * either flattens the percentages or gets flattened by them. CPU % answers the
 * same "is it busy" question in the right unit, and load keeps its own card
 * where a cores line gives it a scale.
 */
export function ResourceUsageChart({ series, metrics, timeZone, stale, ratesReady }) {
  const t = useTranslations("serverDashboard");
  const format = useFormatter();
  const clock = clockFormatter(format, timeZone, { second: "2-digit" });
  const percentTick = (value) =>
    format.number(Number(value) / 100, { style: "percent", maximumFractionDigits: 0 });
  const percentValue = (value) =>
    format.number(Number(value) / 100, { style: "percent", maximumFractionDigits: 1 });

  // Disk joins only when the collector actually reports a filesystem. On a box
  // where disk_total is 0 a flat 0 % line would read as "empty disk" when the
  // truth is "not measured".
  const hasDisk = series.some((p) => p.disk != null);
  const config = {
    cpu: { label: t("cpu"), color: "var(--chart-1)" },
    memory: { label: t("memory"), color: "var(--chart-2)" },
    ...(hasDisk ? { disk: { label: t("disk"), color: "var(--chart-4)" } } : {}),
  };

  return (
    <LiveChartCard
      icon={Gauge}
      title={t("charts.usage.title")}
      description={t("charts.usage.description")}
      ready={series.length >= 2}
      stale={stale}
      // CPU joins only once a second sample exists to measure against — it is
      // a rate, and the API's first 0 means "not measured", not "idle".
      summary={
        metrics?.memory
          ? [
              ratesReady ? `${t("cpu")} ${percentValue(metrics.cpu?.percent)}` : null,
              `${t("memory")} ${percentValue(metrics.memory.percent)}`,
            ]
              .filter(Boolean)
              .join(" · ")
          : null
      }
    >
      <ChartContainer config={config} className="h-72 w-full">
        <AreaChart data={series} margin={{ left: -20, right: 8 }}>
          <defs>
            {Object.entries(config).map(([key, cfg]) => (
              <linearGradient key={key} id={`use-${key}`} x1="0" y1="0" x2="0" y2="1">
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
          {/* Explicit ticks: with only a domain, Recharts picks its own and
              lands on -2% — a negative percentage on a 0-100 scale. */}
          <YAxis
            tickLine={false}
            axisLine={false}
            domain={[0, 100]}
            ticks={[0, 25, 50, 75, 100]}
            tickFormatter={percentTick}
          />
          <ChartTooltip
            content={
              <ChartTooltipContent
                indicator="line"
                labelFormatter={timeLabel(clock)}
                valueFormatter={(v) => percentValue(v)}
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
              fill={`url(#use-${key})`}
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
