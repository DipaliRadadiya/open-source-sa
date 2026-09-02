import { useTranslations, useFormatter } from "next-intl";
import { ArrowDownUp } from "lucide-react";
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

export function NetworkIoChart({ series, metrics, timeZone, stale }) {
  const t = useTranslations("serverDashboard");
  const format = useFormatter();
  const rate = (value) => formatRate(value, format);
  const clock = clockFormatter(format, timeZone, { second: "2-digit" });

  const config = {
    net_in: { label: t("charts.network.in"), color: "var(--chart-2)" },
    net_out: { label: t("charts.network.out"), color: "var(--chart-1)" },
  };

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
              fill={`url(#net-${key})`}
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
