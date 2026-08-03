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

  // Nothing collected yet is a normal state on a server that just started —
  // a slim notice, not a tall empty card.
  if (!data.length) {
    return (
      <div className="flex items-center gap-2.5 rounded-lg border border-dashed bg-muted/30 px-4 py-3 text-sm text-muted-foreground">
        <ChartSpline className="size-4 shrink-0 text-primary" />
        {t("collecting")}
      </div>
    );
  }

  return (
    <Card>
      <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:space-y-0">
        <div className="space-y-1">
          <CardTitle className="flex items-center gap-2 text-lg font-semibold">
            <ChartSpline className="size-4 text-primary" />
            {t("chartTitle")}
          </CardTitle>
          <CardDescription>{t(`chart.${view}`)}</CardDescription>
        </div>

        <Tabs value={view} onValueChange={setView} className="shrink-0">
          <TabsList>
            <TabsTrigger value="qps">{t("qps")}</TabsTrigger>
            <TabsTrigger value="connections">{t("connections")}</TabsTrigger>
            <TabsTrigger value="threads_running">{t("threadsShort")}</TabsTrigger>
          </TabsList>
        </Tabs>
      </CardHeader>

      <CardContent>
        <ChartContainer config={config} className="h-64 w-full">
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
                  labelFormatter={clock}
                  formatter={(value) => decimal(value)}
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
