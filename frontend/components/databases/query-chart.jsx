"use client";

import { useMemo } from "react";
import { Activity, ArrowUp, Minus } from "lucide-react";
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
    <Card className="gap-0 py-0">
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
    () => {
      const built = timeSeriesOption({
        data,
        series: lines,
        tokens,
        xLabel: clock,
        value: decimal,
        // Rates on the left, connection counts on the right: no shared unit.
        axes: [{ formatter: axisNumber }, { minInterval: 1 }],
        zoom: true,
      });
      const muted = tokens["muted-foreground"];
      // The legend is drawn below the chart with each line's live value, so ECharts' own
      // goes, and the grid takes back its band.
      built.legend = { show: false };
      // Bottom: the legend band is gone. Top: room for the peak's label above the line.
      // No end labels: the right axis sits there, and the legend below carries each live value.
      built.grid = { ...built.grid, top: 30, bottom: 56 };
      // Peak and average marked on the queries line, as their boxes above name them.
      if (built.series[0] && peakQps !== null) {
        built.series[0].markPoint = {
          symbol: "circle",
          symbolSize: 7,
          itemStyle: { color: tokens["chart-1"], borderColor: tokens.popover, borderWidth: 2 },
          label: {
            show: true,
            position: "top",
            color: tokens["chart-1"],
            fontSize: 11,
            fontWeight: 600,
            formatter: () => t("summary.peakMark", { value: decimal(peakQps) }),
          },
          data: [{ type: "max" }],
        };
        built.series[0].markLine = {
          silent: true,
          symbol: "none",
          lineStyle: { type: "dashed", color: muted, width: 1 },
          label: {
            position: "insideStartTop",
            color: muted,
            fontSize: 11,
            formatter: () => t("summary.averageMark", { value: decimal(averageQps) }),
          },
          data: [{ yAxis: averageQps }],
        };
      }
      return built;
    },
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

  const latest = (key) => {
    for (let index = data.length - 1; index >= 0; index -= 1) {
      if (data[index][key] !== null) return data[index][key];
    }
    return null;
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle as="h2">{t("chartTitle")}</CardTitle>
        <CardDescription>{t("chartDescription")}</CardDescription>
      </CardHeader>

      <CardContent className="space-y-3">
        <p className="text-xs font-medium text-muted-foreground">{t("qps")}</p>
        {/* Three boxes rather than one strip, each with its own mark, as in the redesign. */}
        <dl className="grid gap-2 sm:grid-cols-3">
          {[
            [t("summary.current"), currentQps, Activity],
            [t("summary.average"), averageQps, Minus],
            [t("summary.peak"), peakQps, ArrowUp],
          ].map(([label, value, Icon]) => (
            <div key={label} className="flex items-center gap-3 rounded-xl border bg-muted/30 px-3 py-2.5">
              <Icon className="size-4 shrink-0 text-muted-foreground" aria-hidden />
              <div className="min-w-0">
                <dt className="text-xs text-muted-foreground">{label}</dt>
                <dd className="text-[15px] font-semibold tabular-nums">
                  {value === null ? "—" : decimal(value)}
                </dd>
              </div>
            </div>
          ))}
        </dl>

        <EChart option={option} dataTable={table} height="h-80" />

        {/* The legend, with each line's latest reading. */}
        <ul className="flex flex-wrap gap-2">
          {lines.map((line) => {
            const value = latest(line.key);
            return (
              <li key={line.key} className="flex items-center gap-2 rounded-full border bg-card px-3 py-1 text-xs">
                <span aria-hidden className="size-2 rounded-full" style={{ backgroundColor: tokens[line.token] }} />
                <span className="text-muted-foreground">{line.label}</span>
                <span className="font-semibold tabular-nums">{value === null ? "—" : decimal(value)}</span>
              </li>
            );
          })}
        </ul>
        {/* Explains the slider, the card's only unlabelled control. */}
        <p className="text-xs text-muted-foreground">{t("zoomHint")}</p>
      </CardContent>
    </Card>
  );
}
