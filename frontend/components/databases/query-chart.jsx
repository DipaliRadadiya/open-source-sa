"use client";

import { useMemo } from "react";
import { Activity } from "lucide-react";
import { useFormatter, useTranslations } from "next-intl";
import { EChart, useChartTokens } from "@/components/ui/echart";
import {
  seriesDataTable,
  timeSeriesOption,
} from "@/lib/charts/time-series-option";
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

  const lines = [
    { key: "qps", label: t("qps"), token: "chart-1", kind: "area", axis: 0 },
    { key: "connections", label: t("connections"), token: "chart-2", axis: 1, width: 1.75 },
    { key: "threads_running", label: t("threadsRunning"), token: "chart-3", axis: 1, width: 1.75 },
  ];

  const option = useMemo(
    () =>
      timeSeriesOption({
        data,
        series: lines,
        tokens,
        xLabel: clock,
        value: decimal,
        // Rates on the left, whole connections on the right: a QPS figure and
        // a connection count share no unit and flatten each other on one axis.
        axes: [{ formatter: axisNumber }, { minInterval: 1 }],
        zoom: true,
      }),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [data, tokens, timeZone],
  );

  const table = useMemo(
    () =>
      seriesDataTable({
        caption: t("chartTitle"),
        timeLabel: t("summary.current"),
        data,
        series: lines,
        xLabel: clock,
        value: decimal,
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
        <EChart option={option} dataTable={table} height="h-72" />
      </CardContent>
    </Card>
  );
}
