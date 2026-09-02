import { useTranslations, useFormatter } from "next-intl";
import { Activity } from "lucide-react";
import { CartesianGrid, Line, LineChart, ReferenceLine, XAxis, YAxis } from "recharts";
import { clockFormatter } from "@/lib/format/time";
import {
  LiveChartCard,
  orderedLegend,
  timeLabel,
} from "@/components/dashboard/live-chart-card";
import {
  ChartContainer,
  ChartTooltip,
  ChartTooltipContent,
  ChartLegend,
} from "@/components/ui/chart";

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
export function ServerLoadChart({ history = [], metrics, timeZone }) {
  const t = useTranslations("serverDashboard");
  const format = useFormatter();
  // No seconds: samples are five minutes apart, so a clock reading 11:05:00
  // implies a precision the data does not have.
  const clock = clockFormatter(format, timeZone);
  const decimal = (value) => format.number(Number(value), { maximumFractionDigits: 2 });
  // Core count is a fact about the machine, not a sample — the live poll is
  // still the honest source for it even on a history chart.
  const cores = Number(metrics?.cpu?.cores) || 0;
  const latest = history.at(-1);

  const config = {
    load_5: { label: "5m", color: "var(--chart-1)" },
    load_15: { label: "15m", color: "var(--chart-2)" },
  };

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
      <ChartContainer config={config} className="h-72 w-full">
        <LineChart data={history} margin={{ left: -20, right: 8 }}>
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
            domain={[0, (max) => Math.max(cores * 1.05, max * 1.2, 1)]}
            tickFormatter={decimal}
          />
          <ChartTooltip
            content={
              <ChartTooltipContent
                indicator="line"
                labelFormatter={timeLabel(clock)}
                valueFormatter={(v) => decimal(v)}
              />
            }
          />
          <ChartLegend content={orderedLegend(config)} />
          {cores ? (
            <ReferenceLine
              y={cores}
              stroke="var(--chart-3)"
              strokeDasharray="6 4"
              strokeWidth={2}
              ifOverflow="extendDomain"
              label={{
                value: t("charts.load.cores", { count: cores }),
                position: "insideTopLeft",
                fill: "var(--chart-3)",
                fontSize: 11,
              }}
            />
          ) : null}
          {Object.keys(config).map((key) => (
            <Line
              key={key}
              type="monotone"
              dataKey={key}
              stroke={config[key].color}
              strokeWidth={2}
              dot={false}
              isAnimationActive={false}
            />
          ))}
        </LineChart>
      </ChartContainer>
    </LiveChartCard>
  );
}
