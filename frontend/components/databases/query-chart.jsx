"use client";

import { useState } from "react";
import { useTranslations, useFormatter } from "next-intl";
import { ChartSpline } from "lucide-react";
import { Area, AreaChart, CartesianGrid, XAxis, YAxis } from "recharts";
import { clockFormatter } from "@/lib/format/time";
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
} from "@/components/ui/chart";

// The API sends "YYYY-MM-DD HH:MM:SS"; normalize to something Date parses.
function sampleTime(value) {
  if (!value) return null;
  const d = new Date(String(value).replace(" ", "T"));
  return Number.isNaN(d.getTime()) ? null : d.getTime();
}

/**
 * The last 24 hours of the engine, as one card you switch views on.
 *
 * Same shape as the server dashboard's chart, deliberately: three separate
 * cards for three series of the same story is 900px of page for one question.
 * Queries per second leads because it is the number that moves when a site
 * gets busy; connections and running threads explain what that did.
 */
export function QueryChart({ metrics = [], timeZone }) {
  const t = useTranslations("databases.monitor");
  const format = useFormatter();
  const [view, setView] = useState("qps");
  const clock = clockFormatter(format, timeZone);
  const decimal = (value) =>
    format.number(Number(value), { maximumFractionDigits: 1 });

  const data = (metrics ?? []).map((point) => ({
    time: sampleTime(point.sampled_at),
    qps: Number(point.qps ?? 0),
    connections: Number(point.connections ?? 0),
    threads_running: Number(point.threads_running ?? 0),
  }));

  const config = {
    qps: { label: t("qps"), color: "var(--chart-1)" },
    connections: { label: t("connections"), color: "var(--chart-2)" },
    threads_running: { label: t("threadsRunning"), color: "var(--chart-4)" },
  };

  // Points that are all zero are "no activity recorded", not a trend. Drawing
  // 288 of them as a flat line costs 360px of page to say nothing, so it gets
  // the same slim notice as having no points at all.
  const hasActivity = data.some(
    (point) => point.qps > 0 || point.connections > 0 || point.threads_running > 0,
  );

  // Of the series currently on screen — switching the tab switches these too,
  // because "peak 391" means nothing without knowing peak of what.
  const values = data.map((point) => point[view] ?? 0);
  const summary = {
    current: values.at(-1) ?? 0,
    peak: values.length ? Math.max(...values) : 0,
    average: values.length ? values.reduce((sum, v) => sum + v, 0) / values.length : 0,
  };

  // Nothing collected yet is a normal state on a server that just started —
  // a slim notice, not a tall empty card.
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
      <CardHeader className="flex flex-col gap-3 pb-1 lg:flex-row lg:items-start lg:justify-between lg:space-y-0">
        <div className="space-y-1">
          <CardTitle className="flex items-center gap-2 text-lg font-semibold">
            <ChartSpline className="size-4 text-primary" />
            {t("chartTitle")}
          </CardTitle>
          <CardDescription>{t(`chart.${view}`)}</CardDescription>
        </div>

        {/* Wrapping list, same as every other tab strip in the panel: three
            full-length labels are wider than a phone card, and a clipped
            "Threads" reads as a broken card rather than a third view. */}
        <Tabs value={view} onValueChange={setView} className="min-w-0 lg:shrink-0">
          <TabsList className="!h-auto w-fit flex-wrap gap-1 p-1">
            <TabsTrigger value="qps" className="px-3 py-1.5">
              {t("qps")}
            </TabsTrigger>
            <TabsTrigger value="connections" className="px-3 py-1.5">
              {t("connections")}
            </TabsTrigger>
            <TabsTrigger value="threads_running" className="px-3 py-1.5">
              {t("threadsShort")}
            </TabsTrigger>
          </TabsList>
        </Tabs>
      </CardHeader>

      <CardContent className="space-y-3">
        {/* Three numbers off the same series the chart is drawing. A 24-hour
            line answers "what shape", not "what is it now" or "how bad did it
            get" — and those are the two people actually ask. Compact, because
            this is context for the line below it, not a stat row of its own. */}
        <dl className="grid grid-cols-3 gap-3 rounded-lg border bg-muted/30 px-4 py-2.5">
          {[
            ["current", summary.current],
            ["peak", summary.peak],
            ["average", summary.average],
          ].map(([key, value]) => (
            <div key={key} className="min-w-0">
              <dt className="text-[11px] uppercase tracking-wide text-muted-foreground">
                {t(`summary.${key}`)}
              </dt>
              <dd className="truncate text-sm font-semibold tabular-nums">
                {decimal(value)}
              </dd>
            </div>
          ))}
        </dl>

        <ChartContainer config={config} className="h-52 w-full">
          <AreaChart data={data} margin={{ left: -20, right: 8 }}>
            <defs>
              <linearGradient id="fill-db" x1="0" y1="0" x2="0" y2="1">
                <stop offset="5%" stopColor={config[view].color} stopOpacity={0.35} />
                <stop offset="95%" stopColor={config[view].color} stopOpacity={0.03} />
              </linearGradient>
            </defs>
            <CartesianGrid vertical={false} strokeDasharray="3 3" />
            <XAxis
              dataKey="time"
              tickLine={false}
              axisLine={false}
              minTickGap={40}
              tickFormatter={clock}
            />
            <YAxis tickLine={false} axisLine={false} tickFormatter={decimal} />
            <ChartTooltip
              content={
                <ChartTooltipContent
                  indicator="line"
                  labelFormatter={(_, payload) => clock(payload?.[0]?.payload?.time)}
                  valueFormatter={(value) => decimal(value)}
                />
              }
            />
            <Area
              type="monotone"
              dataKey={view}
              stroke={config[view].color}
              fill="url(#fill-db)"
              strokeWidth={2}
              dot={false}
            />
          </AreaChart>
        </ChartContainer>
      </CardContent>
    </Card>
  );
}
