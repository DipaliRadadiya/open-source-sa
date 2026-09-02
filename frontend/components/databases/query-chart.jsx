"use client";

import { useMemo } from "react";
import { Activity } from "lucide-react";
import { useFormatter, useTranslations } from "next-intl";
import { EChart, useChartTokens } from "@/components/ui/echart";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { clockFormatter } from "@/lib/format/time";
import { historySeries } from "@/lib/server/history-series";

/** Tokens this chart draws with, resolved from globals.css at runtime. */
const TOKENS = [
  "chart-1",
  "chart-2",
  "chart-3",
  "border",
  "muted-foreground",
  "popover",
  "popover-foreground",
];

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

export function QueryChart({ metrics = [], timeZone }) {
  const t = useTranslations("databases.monitor");
  const format = useFormatter();
  const tokens = useChartTokens(TOKENS);

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

  const qpsValues = data
    .map((point) => point.qps)
    .filter((value) => value !== null);
  const currentQps = data.at(-1)?.qps ?? null;
  const peakQps = qpsValues.length ? Math.max(...qpsValues) : null;
  const averageQps = qpsValues.length
    ? qpsValues.reduce((total, value) => total + value, 0) / qpsValues.length
    : null;

  const labels = {
    qps: t("qps"),
    connections: t("connections"),
    threads_running: t("threadsRunning"),
  };

  // Rebuilt only when the data or the resolved palette changes; EChart calls
  // setOption on every new object it is handed.
  const option = useMemo(() => {
    if (!tokens["chart-1"]) return null;

    const line = (key, axis, colour) => ({
      name: labels[key],
      type: "line",
      yAxisIndex: axis,
      showSymbol: false,
      smooth: true,
      // A gap in the samples is a gap in what we know. Joining across it would
      // draw a straight line through a collector outage as though it were data.
      connectNulls: false,
      lineStyle: { width: key === "qps" ? 2 : 1.75, color: colour },
      itemStyle: { color: colour },
      data: data.map((point) => [point.t, point[key]]),
    });

    const qps = line("qps", 0, tokens["chart-1"]);
    qps.areaStyle = { color: tokens["chart-1"], opacity: 0.18 };

    return {
      // ECharts' own description, alongside the hidden table EChart renders.
      aria: { enabled: true, decal: { show: true } },
      animation: false,
      grid: { left: 52, right: 44, top: 16, bottom: 64, containLabel: false },
      legend: {
        // Declaration order, so the legend matches the summary above it. The
        // Recharts version needed a custom content component to achieve this.
        data: [labels.qps, labels.connections, labels.threads_running],
        bottom: 28,
        icon: "roundRect",
        itemWidth: 10,
        itemHeight: 10,
        textStyle: { color: tokens["muted-foreground"] },
      },
      tooltip: {
        trigger: "axis",
        backgroundColor: tokens.popover,
        borderColor: tokens.border,
        textStyle: { color: tokens["popover-foreground"] },
        formatter: (params) => {
          const header = clock(params[0]?.value?.[0]);
          const rows = params
            .filter((p) => p.value?.[1] !== null && p.value?.[1] !== undefined)
            .map(
              (p) =>
                `<div style="display:flex;gap:.75rem;justify-content:space-between">
                   <span>${p.marker} ${p.seriesName}</span>
                   <strong>${decimal(p.value[1])}</strong>
                 </div>`,
            )
            .join("");

          return `<div style="font-weight:600;margin-bottom:.25rem">${header}</div>${rows}`;
        },
      },
      // The reason for the migration: drag inside the plot to zoom a range,
      // wheel to scale, and a slider underneath for coarse selection.
      dataZoom: [
        { type: "inside", throttle: 50 },
        {
          type: "slider",
          height: 18,
          bottom: 4,
          borderColor: tokens.border,
          textStyle: { color: tokens["muted-foreground"] },
        },
      ],
      xAxis: {
        type: "time",
        axisLine: { show: false },
        axisTick: { show: false },
        splitLine: { show: false },
        axisLabel: {
          color: tokens["muted-foreground"],
          hideOverlap: true,
          formatter: (value) => clock(value),
        },
      },
      yAxis: [
        {
          type: "value",
          min: 0,
          axisLine: { show: false },
          axisTick: { show: false },
          splitLine: { lineStyle: { color: tokens.border, type: "dashed" } },
          axisLabel: {
            color: tokens["muted-foreground"],
            formatter: (value) => axisNumber(value),
          },
        },
        {
          type: "value",
          min: 0,
          minInterval: 1,
          axisLine: { show: false },
          axisTick: { show: false },
          splitLine: { show: false },
          axisLabel: { color: tokens["muted-foreground"] },
        },
      ],
      series: [
        qps,
        line("connections", 1, tokens["chart-2"]),
        line("threads_running", 1, tokens["chart-3"]),
      ],
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data, tokens, timeZone]);

  const dataTable = useMemo(
    () => ({
      caption: t("chartTitle"),
      columns: [
        t("summary.current"),
        labels.qps,
        labels.connections,
        labels.threads_running,
      ],
      rows: data.map((point) => [
        clock(point.t),
        point.qps === null ? "—" : decimal(point.qps),
        point.connections === null ? "—" : decimal(point.connections),
        point.threads_running === null ? "—" : decimal(point.threads_running),
      ]),
    }),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [data, timeZone],
  );

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
        <EChart option={option} dataTable={dataTable} height="h-72" />
      </CardContent>
    </Card>
  );
}
