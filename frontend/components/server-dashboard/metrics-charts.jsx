"use client";

import { useState } from "react";
import { useTranslations, useFormatter } from "next-intl";
import { ChartSpline } from "lucide-react";
import { formatRate } from "@/lib/format/bytes";
import { clockFormatter } from "@/lib/format/time";
import {
  Area,
  AreaChart,
  CartesianGrid,
  Line,
  LineChart,
  XAxis,
  YAxis,
} from "recharts";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  CardDescription,
} from "@/components/ui/card";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  ChartContainer,
  ChartTooltip,
  ChartTooltipContent,
  ChartLegend,
  ChartLegendContent,
} from "@/components/ui/chart";

// The API sends "YYYY-MM-DD HH:MM:SS"; normalize to something Date parses.
function sampleTime(value) {
  if (!value) return null;
  const d = new Date(String(value).replace(" ", "T"));
  return Number.isNaN(d.getTime()) ? null : d.getTime();
}

/**
 * The last 24 hours, as ONE card you switch views on.
 *
 * This was three stacked cards — usage, load and network — roughly 1200px of
 * page for three answers to the same question ("what has been happening?").
 * Worse, the network one duplicated the live throughput chart further up, so
 * the page carried two charts of the same metric. Tabs make it one card, one
 * chart on screen at a time, and leave an obvious home for a time-range picker
 * when the API can serve one.
 *
 * The usage view deliberately plots CPU and memory only. Swap and disk are
 * near-flat over a day and disk is already a stat card two rows above — on a
 * shared 0–100 axis they were noise that made the two moving series harder to
 * read.
 */
export function MetricsCharts({ history, timeZone }) {
  const t = useTranslations("serverDashboard");
  const format = useFormatter();
  const [view, setView] = useState("usage");
  const rate = (value) => formatRate(value, format);
  const clock = clockFormatter(format, timeZone);
  const percentTick = (value) =>
    format.number(Number(value) / 100, { style: "percent", maximumFractionDigits: 0 });
  const percentValue = (value) =>
    format.number(Number(value) / 100, { style: "percent", maximumFractionDigits: 1 });
  // Without an explicit formatter the shadcn tooltip falls back to
  // Number#toLocaleString, which ignores the app locale.
  const decimal = (value) => format.number(Number(value), { maximumFractionDigits: 2 });

  const data = (history ?? []).map((p) => ({
    time: sampleTime(p.sampled_at),
    cpu: Number(p.cpu ?? 0),
    memory: Number(p.memory ?? 0),
    load_1: Number(p.load_1 ?? 0),
    load_5: Number(p.load_5 ?? 0),
    load_15: Number(p.load_15 ?? 0),
    net_in: Number(p.net_in ?? 0),
    net_out: Number(p.net_out ?? 0),
  }));

  const usageConfig = {
    cpu: { label: t("cpu"), color: "var(--chart-1)" },
    memory: { label: t("memory"), color: "var(--chart-2)" },
  };
  const netConfig = {
    net_in: { label: t("charts.networkIn"), color: "var(--chart-2)" },
    net_out: { label: t("charts.networkOut"), color: "var(--chart-1)" },
  };
  const loadConfig = {
    load_1: { label: "1m", color: "var(--chart-1)" },
    load_5: { label: "5m", color: "var(--chart-2)" },
    load_15: { label: "15m", color: "var(--chart-4)" },
  };

  // No history yet: a slim inline notice, not a tall empty card.
  if (!data.length) {
    return (
      <div className="flex items-center gap-2.5 rounded-lg border border-dashed bg-muted/30 px-4 py-3 text-sm text-muted-foreground">
        <ChartSpline className="size-4 shrink-0 text-primary" />
        {t("charts.collecting")}
      </div>
    );
  }

  const axis = (
    <>
      <CartesianGrid vertical={false} strokeDasharray="3 3" />
      <XAxis
        dataKey="time"
        tickLine={false}
        axisLine={false}
        minTickGap={40}
        tickFormatter={clock}
      />
    </>
  );

  return (
    <Card>
      <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:space-y-0">
        <div className="space-y-1">
          <CardTitle className="flex items-center gap-2 text-lg font-semibold">
            <ChartSpline className="size-4 text-primary" />
            {t("charts.title")}
          </CardTitle>
          <CardDescription>{t(`charts.${view}Description`)}</CardDescription>
        </div>

        {/* Top-right, where a dashboard's view switch belongs — and where the
            time-range picker will sit beside it once the API can serve one. */}
        <Tabs value={view} onValueChange={setView} className="shrink-0">
          <TabsList>
            <TabsTrigger value="usage">{t("charts.usage")}</TabsTrigger>
            <TabsTrigger value="load">{t("charts.load")}</TabsTrigger>
            <TabsTrigger value="network">{t("charts.network")}</TabsTrigger>
          </TabsList>
        </Tabs>
      </CardHeader>

      <CardContent>
        {view === "usage" ? (
          <ChartContainer config={usageConfig} className="h-64 w-full">
            <AreaChart data={data} margin={{ left: -20, right: 8 }}>
              <defs>
                {Object.entries(usageConfig).map(([key, cfg]) => (
                  <linearGradient key={key} id={`fill-${key}`} x1="0" y1="0" x2="0" y2="1">
                    <stop offset="5%" stopColor={cfg.color} stopOpacity={0.35} />
                    <stop offset="95%" stopColor={cfg.color} stopOpacity={0.03} />
                  </linearGradient>
                ))}
              </defs>
              {axis}
              <YAxis
                tickLine={false}
                axisLine={false}
                domain={[0, 100]}
                tickFormatter={percentTick}
              />
              <ChartTooltip
                content={
                  <ChartTooltipContent
                    indicator="line"
                    labelFormatter={clock}
                    formatter={(v) => percentValue(v)}
                  />
                }
              />
              <ChartLegend content={<ChartLegendContent />} />
              {Object.keys(usageConfig).map((key) => (
                <Area
                  key={key}
                  type="monotone"
                  dataKey={key}
                  stroke={usageConfig[key].color}
                  fill={`url(#fill-${key})`}
                  strokeWidth={2}
                  dot={false}
                />
              ))}
            </AreaChart>
          </ChartContainer>
        ) : null}

        {view === "load" ? (
          <ChartContainer config={loadConfig} className="h-64 w-full">
            <LineChart data={data} margin={{ left: -20, right: 8 }}>
              {axis}
              <YAxis tickLine={false} axisLine={false} tickFormatter={decimal} />
              <ChartTooltip
                content={
                  <ChartTooltipContent
                    indicator="line"
                    labelFormatter={clock}
                    formatter={(v) => decimal(v)}
                  />
                }
              />
              <ChartLegend content={<ChartLegendContent />} />
              {Object.keys(loadConfig).map((key) => (
                <Line
                  key={key}
                  type="monotone"
                  dataKey={key}
                  stroke={loadConfig[key].color}
                  strokeWidth={2}
                  dot={false}
                />
              ))}
            </LineChart>
          </ChartContainer>
        ) : null}

        {view === "network" ? (
          <ChartContainer config={netConfig} className="h-64 w-full">
            <AreaChart data={data} margin={{ left: -4, right: 8 }}>
              <defs>
                {Object.entries(netConfig).map(([key, cfg]) => (
                  <linearGradient key={key} id={`hist-${key}`} x1="0" y1="0" x2="0" y2="1">
                    <stop offset="5%" stopColor={cfg.color} stopOpacity={0.35} />
                    <stop offset="95%" stopColor={cfg.color} stopOpacity={0.03} />
                  </linearGradient>
                ))}
              </defs>
              {axis}
              <YAxis tickLine={false} axisLine={false} width={64} tickFormatter={rate} />
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
              {Object.keys(netConfig).map((key) => (
                <Area
                  key={key}
                  type="monotone"
                  dataKey={key}
                  stroke={netConfig[key].color}
                  fill={`url(#hist-${key})`}
                  strokeWidth={2}
                  dot={false}
                />
              ))}
            </AreaChart>
          </ChartContainer>
        ) : null}
      </CardContent>
    </Card>
  );
}
