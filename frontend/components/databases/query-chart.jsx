"use client";

import { useEffect, useRef, useState } from "react";
import { Activity } from "lucide-react";
import { useFormatter, useTranslations } from "next-intl";
import {
  Area,
  CartesianGrid,
  ComposedChart,
  Line,
  XAxis,
  YAxis,
} from "recharts";
import {
  orderedLegend,
  timeLabel,
} from "@/components/dashboard/live-chart-card";
import {
  ChartContainer,
  ChartLegend,
  ChartTooltip,
  ChartTooltipContent,
} from "@/components/ui/chart";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { clockFormatter } from "@/lib/format/time";
import { historySeries } from "@/lib/server/history-series";
import { cn } from "@/lib/utils";

function metricValue(value) {
  if (value === null || value === undefined || value === "") return null;
  const number = Number(value);
  return Number.isFinite(number) ? number : null;
}

function querySeries(metrics) {
  return historySeries(metrics).map((point) => ({
    t: point.t,
    qps: metricValue(point.qps),
    connections: metricValue(point.connections),
    threads_running: metricValue(point.threads_running),
  }));
}

function ChartNotice({ message }) {
  return (
    <Card>
      <CardContent className="flex items-center gap-3 py-5 text-sm text-muted-foreground">
        <Activity className="size-4 shrink-0" />
        <p>{message}</p>
      </CardContent>
    </Card>
  );
}

function ChartViewport({ children, config }) {
  const rootRef = useRef(null);
  const [ready, setReady] = useState(false);

  useEffect(() => {
    const root = rootRef.current;
    if (!root) return;

    let observer;
    const revealIfReady = () => {
      if (!root.querySelector("svg")) return;
      setReady(true);
      observer?.disconnect();
    };

    observer = new MutationObserver(revealIfReady);
    observer.observe(root, { childList: true, subtree: true });
    const frame = window.requestAnimationFrame(revealIfReady);

    return () => {
      window.cancelAnimationFrame(frame);
      observer.disconnect();
    };
  }, []);

  return (
    <div ref={rootRef} className="relative h-72" aria-busy={!ready}>
      {!ready ? (
        <Skeleton
          className="absolute inset-0 z-10 h-full w-full rounded-lg"
          aria-hidden="true"
        />
      ) : null}
      <ChartContainer
        config={config}
        className={cn("h-72 w-full", !ready && "invisible")}
      >
        {children}
      </ChartContainer>
    </div>
  );
}

export function QueryChart({ metrics = [], timeZone }) {
  const t = useTranslations("databases.monitor");
  const format = useFormatter();
  const data = querySeries(metrics);
  const clock = clockFormatter(format, timeZone);
  const decimal = (value) =>
    format.number(Number(value), { maximumFractionDigits: 2 });
  const axisNumber = (value) => {
    const number = Number(value);
    const compact = Math.abs(number) >= 1000;

    return format.number(number, {
      notation: compact ? "compact" : "standard",
      maximumFractionDigits: compact ? 1 : 2,
    });
  };

  if (data.length < 2) {
    return <ChartNotice message={t("collecting")} />;
  }

  const hasActivity = data.some((point) =>
    [point.qps, point.connections, point.threads_running].some(
      (value) => value !== null && value > 0,
    ),
  );

  if (!hasActivity) {
    return <ChartNotice message={t("noActivity")} />;
  }

  const qpsValues = data
    .map((point) => point.qps)
    .filter((value) => value !== null);
  const currentQps = data.at(-1)?.qps ?? null;
  const peakQps = qpsValues.length ? Math.max(...qpsValues) : null;
  const averageQps = qpsValues.length
    ? qpsValues.reduce((total, value) => total + value, 0) / qpsValues.length
    : null;

  // These concrete colors are intentional: Recharts SVG strokes have failed
  // to resolve chart CSS variables in this card in production builds.
  const config = {
    qps: {
      label: t("qps"),
      color: "hsl(221 83% 53%)",
    },
    connections: {
      label: t("connections"),
      color: "hsl(142 71% 38%)",
    },
    threads_running: {
      label: t("threadsRunning"),
      color: "hsl(38 92% 45%)",
    },
  };

  return (
    <Card>
      <CardHeader className="gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="space-y-1">
          <CardTitle as="h2">{t("chartTitle")}</CardTitle>
          <CardDescription>{t("chartDescription")}</CardDescription>
        </div>

        <div className="shrink-0">
          <p className="mb-1.5 text-xs font-medium text-muted-foreground">
            {t("qps")}
          </p>
          <dl className="grid grid-cols-3 gap-4 rounded-lg border bg-muted/30 px-3 py-2">
            {[
              [t("summary.current"), currentQps],
              [t("summary.average"), averageQps],
              [t("summary.peak"), peakQps],
            ].map(([label, value]) => (
              <div key={label} className="min-w-12">
                <dt className="text-xs text-muted-foreground">{label}</dt>
                <dd className="mt-0.5 text-sm font-semibold tabular-nums">
                  {value === null ? "—" : decimal(value)}
                </dd>
              </div>
            ))}
          </dl>
        </div>
      </CardHeader>

      <CardContent>
        <ChartViewport config={config}>
          <ComposedChart data={data} margin={{ left: 8, right: 4, top: 8 }}>
            <defs>
              <linearGradient id="database-qps" x1="0" y1="0" x2="0" y2="1">
                <stop offset="5%" stopColor={config.qps.color} stopOpacity={0.28} />
                <stop offset="95%" stopColor={config.qps.color} stopOpacity={0.02} />
              </linearGradient>
            </defs>

            <CartesianGrid vertical={false} strokeDasharray="3 3" />
            <XAxis
              dataKey="t"
              type="number"
              scale="time"
              domain={["dataMin", "dataMax"]}
              tickLine={false}
              axisLine={false}
              tickCount={5}
              minTickGap={40}
              tickFormatter={clock}
            />
            <YAxis
              yAxisId="qps"
              tickLine={false}
              axisLine={false}
              width={48}
              tickCount={4}
              domain={[0, (max) => Math.max(1, Number(max) * 1.1)]}
              tickFormatter={axisNumber}
            />
            <YAxis
              yAxisId="count"
              orientation="right"
              tickLine={false}
              axisLine={false}
              width={36}
              tickCount={4}
              allowDecimals={false}
              domain={[0, (max) => Math.max(1, Math.ceil(Number(max) * 1.1))]}
            />

            <ChartTooltip
              content={
                <ChartTooltipContent
                  indicator="line"
                  labelFormatter={timeLabel(clock)}
                  valueFormatter={(value) => decimal(value)}
                />
              }
            />
            <ChartLegend content={orderedLegend(config)} />

            <Area
              yAxisId="qps"
              type="monotone"
              dataKey="qps"
              stroke={config.qps.color}
              fill="url(#database-qps)"
              strokeWidth={2}
              dot={false}
              connectNulls={false}
              isAnimationActive={false}
            />
            <Line
              yAxisId="count"
              type="monotone"
              dataKey="connections"
              stroke={config.connections.color}
              strokeWidth={1.75}
              dot={false}
              connectNulls={false}
              isAnimationActive={false}
            />
            <Line
              yAxisId="count"
              type="monotone"
              dataKey="threads_running"
              stroke={config.threads_running.color}
              strokeWidth={1.75}
              dot={false}
              connectNulls={false}
              isAnimationActive={false}
            />
          </ComposedChart>
        </ChartViewport>
      </CardContent>
    </Card>
  );
}
