"use client";

import { useTranslations, useFormatter } from "next-intl";
import { ChartSpline, CircleDot, Gauge } from "lucide-react";
import {
  ComposedChart,
  CartesianGrid,
  XAxis,
  YAxis,
  Line,
  Legend,
  Tooltip,
  ResponsiveContainer,
} from "recharts";
import { clockFormatter } from "@/lib/format/time";
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
} from "@/components/ui/chart";

// The API sends "YYYY-MM-DD HH:MM:SS"; normalize to something Date parses.
function sampleTime(value) {
  if (!value) return null;
  const d = new Date(String(value).replace(" ", "T"));
  return Number.isNaN(d.getTime()) ? null : d.getTime();
}

/**
 * The last 24 hours of the engine, as one chart with three lines.
 *
 * QPS (queries/sec) gets its own left Y-axis because it can be 1000+.
 * Connections and threads_running share the right Y-axis — both are small
 * integers (0–few hundred) that make sense on the same scale.
 * Showing them as separate tabs hid the relationship between them; now you
 * can see whether a QPS spike drove connections up, and whether threads kept
 * pace.
 */
export function QueryChart({ metrics = [], timeZone }) {
  const t = useTranslations("databases.monitor");
  const format = useFormatter();
  const clock = clockFormatter(format, timeZone);
  const decimal = (value) =>
    format.number(Number(value), { maximumFractionDigits: 1 });

  const data = (metrics ?? []).map((point) => ({
    time: sampleTime(point.sampled_at),
    qps: Number(point.qps ?? 0),
    connections: Number(point.connections ?? 0),
    threads_running: Number(point.threads_running ?? 0),
  }));

  // Concrete hex colors (not CSS vars) so Recharts 3.x SVG strokes always render.
  // Chart-1 = blue, chart-2 = teal, chart-4 = purple — matching the CSS theme.
  const COLORS = {
    qps: "hsl(221 83% 53%)",       // blue — left axis
    connections: "hsl(173 58% 39%)", // teal — right axis
    threads_running: "hsl(262 83% 58%)", // purple — right axis
  };

  const config = {
    qps: {
      label: t("qps"),
      color: COLORS.qps,
      icon: <CircleDot className="size-3" />,
      yAxis: "left",
    },
    connections: {
      label: t("connections"),
      color: COLORS.connections,
      icon: null,
      yAxis: "right",
    },
    threads_running: {
      label: t("threadsRunning"),
      color: COLORS.threads_running,
      icon: null,
      yAxis: "right",
    },
  };

  // Points that are all zero are "no activity recorded", not a trend.
  const hasActivity = data.some(
    (point) => point.qps > 0 || point.connections > 0 || point.threads_running > 0,
  );

  // Stats for QPS — the primary metric on the left axis.
  const qpsValues = data.map((p) => p.qps ?? 0);
  const qpsSummary = {
    current: qpsValues.at(-1) ?? 0,
    peak: qpsValues.length ? Math.max(...qpsValues) : 0,
    average:
      qpsValues.length
        ? qpsValues.reduce((sum, v) => sum + v, 0) / qpsValues.length
        : 0,
  };

  if (!data.length || !hasActivity) {
    return (
      <div className="flex items-center gap-2.5 rounded-lg border border-dashed bg-muted/30 px-4 py-3 text-sm text-muted-foreground">
        <ChartSpline className="size-4 shrink-0 text-primary" />
        {t("collecting")}
      </div>
    );
  }

  return (
    <Card>
      <CardHeader className="pb-2">
        <div className="flex items-center gap-2">
          <ChartSpline className="size-4 text-primary" />
          <CardTitle className="text-lg font-semibold">{t("chartTitle")}</CardTitle>
        </div>
        <CardDescription>
          {t("chartDescription")}
        </CardDescription>
      </CardHeader>

      <CardContent className="space-y-3">
        {/* Compact QPS stat row — the left-axis metric. Connections and threads
            are on the right axis and are readable off the chart itself. */}
        <dl className="grid grid-cols-3 gap-3 rounded-lg border bg-muted/30 px-4 py-2.5">
          {[
            ["current", qpsSummary.current],
            ["peak", qpsSummary.peak],
            ["average", qpsSummary.average],
          ].map(([key, value]) => (
            <div key={key} className="min-w-0">
              <dt className="text-[11px] uppercase tracking-wide text-muted-foreground">
                {t(`summary.${key}`)} {t("qps")}
              </dt>
              <dd className="truncate text-sm font-semibold tabular-nums">
                {decimal(value)}
              </dd>
            </div>
          ))}
        </dl>

        <ChartContainer config={config} className="h-60 w-full">
          <ComposedChart data={data} margin={{ left: 0, right: 8 }}>
            <CartesianGrid vertical={false} strokeDasharray="3 3" />
            <XAxis
              dataKey="time"
              tickLine={false}
              axisLine={false}
              minTickGap={40}
              tickFormatter={clock}
            />
            {/* Left axis: QPS — can be in the thousands */}
            <YAxis
              yAxisId="left"
              orientation="left"
              tickLine={false}
              axisLine={false}
              tickFormatter={decimal}
              width={40}
            />
            {/* Right axis: connections + threads — typically 0–hundreds */}
            <YAxis
              yAxisId="right"
              orientation="right"
              tickLine={false}
              axisLine={false}
              tickFormatter={decimal}
              width={36}
            />
            <ChartTooltip
              content={
                <ChartTooltipContent>
                  {({ active, payload, label }) => {
                    if (!active || !payload?.length) return null;
                    return (
                      <div className="space-y-1 rounded-md border bg-background/95 px-3 py-2 shadow-sm">
                        <p className="text-xs text-muted-foreground">
                          {clock(payload[0]?.payload?.time)}
                        </p>
                        {payload.map((entry) => (
                          <div
                            key={entry.dataKey}
                            className="flex items-center justify-between gap-4 text-sm"
                          >
                            <span
                              className="flex items-center gap-1.5"
                              style={{ color: entry.color }}
                            >
                              <span
                                className="size-2 rounded-full"
                                style={{ backgroundColor: entry.color }}
                              />
                              {config[entry.dataKey]?.label ?? entry.dataKey}
                            </span>
                            <span className="font-mono font-semibold tabular-nums">
                              {decimal(entry.value)}
                            </span>
                          </div>
                        ))}
                      </div>
                    );
                  }}
                </ChartTooltipContent>
              }
            />
            <Legend
              verticalAlign="top"
              align="right"
              height={32}
              iconType="circle"
              iconSize={8}
              formatter={(value) =>
                config[value]?.label ?? value
              }
            />
            <Line
              yAxisId="left"
              type="monotone"
              dataKey="qps"
              stroke={config.qps.color}
              strokeWidth={2}
              dot={false}
              name="qps"
            />
            <Line
              yAxisId="right"
              type="monotone"
              dataKey="connections"
              stroke={config.connections.color}
              strokeWidth={2}
              dot={false}
              name="connections"
            />
            <Line
              yAxisId="right"
              type="monotone"
              dataKey="threads_running"
              stroke={config.threads_running.color}
              strokeWidth={2}
              dot={false}
              name="threads_running"
            />
          </ComposedChart>
        </ChartContainer>

        {/* Axis scale hint when QPS is significant */}
        {qpsSummary.peak > 0 && (
          <p className="text-xs text-muted-foreground">
            <CircleDot className="mr-0.5 inline size-2.5" style={{ color: config.qps.color }} />
            {t("qps")} ·{" "}
            <Gauge className="mr-0.5 inline size-2.5" style={{ color: config.connections.color }} />
            {t("connections")} ·{" "}
            {t("threadsRunning")}
          </p>
        )}
      </CardContent>
    </Card>
  );
}
