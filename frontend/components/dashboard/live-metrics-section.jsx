"use client";

import { useEffect, useRef, useState } from "react";
import dynamic from "next/dynamic";
import { useTranslations, useFormatter } from "next-intl";
import { CircleAlert } from "lucide-react";
import { cn } from "@/lib/utils";
import { Card, CardContent, CardHeader, CardTitle, CardAction } from "@/components/ui/card";
import { clockFormatter } from "@/lib/format/time";
import { useLiveMetrics } from "@/components/dashboard/use-live-metrics";
import { StatCards } from "@/components/dashboard/stat-cards";
import { ChartCardSkeleton, LiveChartCardSkeleton } from "@/components/dashboard/chart-card-skeleton";

// Recharts is ~400 KB on the login landing screen. `ssr: false`: nothing real renders on the server.
const chart = (load, loading = ChartCardSkeleton) => dynamic(load, { ssr: false, loading });

const ServerLoadChart = chart(() =>
  import("@/components/dashboard/server-load-chart").then((m) => m.ServerLoadChart),
);
const ResourceUsageChart = chart(() =>
  import("@/components/dashboard/resource-usage-chart").then((m) => m.ResourceUsageChart),
);
const NetworkIoChart = chart(
  () => import("@/components/dashboard/network-io-chart").then((m) => m.NetworkIoChart),
  LiveChartCardSkeleton,
);
const DiskIoChart = chart(
  () => import("@/components/dashboard/disk-io-chart").then((m) => m.DiskIoChart),
  LiveChartCardSkeleton,
);

/** Announce only meaningful connection transitions, never every metric poll. */
function ConnectionAnnouncement({ failed }) {
  const t = useTranslations("serverDashboard");
  const previous = useRef(failed);
  const [message, setMessage] = useState("");

  useEffect(() => {
    if (previous.current === failed) return;
    previous.current = failed;
    setMessage(failed ? t("offline") : t("recovered"));
  }, [failed, t]);

  return (
    <span className="sr-only" aria-live="polite" aria-atomic="true">
      {message}
    </span>
  );
}

function LiveStatus({ failed, reason, updatedAt, timeZone }) {
  const t = useTranslations("serverDashboard");
  const format = useFormatter();
  // timeZoneName only here: the "Updated" label must say which clock it means.
  const clock = clockFormatter(format, timeZone, {
    second: "2-digit",
    timeZoneName: "short",
  });

  return (
    <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
      <span
        className={cn(
          // A solid soft pill, as in the redesign.
          "inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium",
          failed ? "bg-destructive-soft text-destructive" : "bg-success-soft text-success",
        )}
      >
        {failed ? (
          <CircleAlert className="size-3.5" />
        ) : (
          <span className="relative flex size-2">
            <span className="absolute inline-flex size-full animate-ping rounded-full bg-success opacity-75 motion-reduce:hidden" />
            <span className="relative inline-flex size-2 rounded-full bg-success" />
          </span>
        )}
        {failed ? t("offline") : t("live")}
      </span>
      {/* Kept visible while failing — "how old is this?" matters most then. */}
      {updatedAt ? (
        <span className="text-xs tabular-nums text-muted-foreground">
          {t("updated", { time: clock(updatedAt) })}
        </span>
      ) : null}
      {failed && updatedAt ? (
        <span className="text-xs text-muted-foreground">{t("staleHint")}</span>
      ) : null}
      {/* The server's own reason, inline rather than behind a hover. */}
      {failed && reason ? (
        <span className="text-xs text-destructive">{reason}</span>
      ) : null}
    </div>
  );
}

// h2 under the page h1, so the cards inside use h3.
function SectionHeading({ title, children }) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
      <h2 className="text-base font-semibold tracking-tight">
        {title}
      </h2>
      {children}
    </div>
  );
}

// `between` renders after the live readings and before the charts; it shares this
// component's single poll instead of starting a second one.
export function LiveMetricsSection({ timeZone, history = [], between = null }) {
  const t = useTranslations("serverDashboard");
  const { metrics, series, failed, reason, updatedAt, ratesReady } = useLiveMetrics();
  // Everything on screen is last-known, not current; the charts must show it too.
  const stale = failed && Boolean(metrics);

  return (
    <div className="space-y-6">
      {/* Grouped by clock: the 3s live poll vs the five-minute 24h collector. */}
      <ConnectionAnnouncement failed={failed} />

      {/* The Live pill is this section's status, so it sits in the heading row. */}
      <Card className="[--card-spacing:--spacing(5)]">
        {/* Stacked on a phone: beside the title the pill squeezed it onto two lines. */}
        <CardHeader className="max-sm:grid-cols-1">
          <CardTitle as="h2">
            {t("liveLabel")}
          </CardTitle>
          <CardAction className="max-sm:col-start-1 max-sm:row-span-1 max-sm:row-start-2 max-sm:justify-self-start">
            <LiveStatus failed={failed} reason={reason} updatedAt={updatedAt} timeZone={timeZone} />
          </CardAction>
        </CardHeader>
        <CardContent>
          <StatCards metrics={metrics} stale={stale} ratesReady={ratesReady} />
        </CardContent>
      </Card>

      {between}

      <div className="grid gap-6 lg:grid-cols-2">
        <NetworkIoChart
          series={series}
          metrics={metrics}
          timeZone={timeZone}
          stale={stale}
        />
        <DiskIoChart series={series} metrics={metrics} timeZone={timeZone} stale={stale} />
      </div>

      <section className="space-y-4">
        <SectionHeading title={t("historyLabel")} />
        <div className="grid gap-6 lg:grid-cols-2">
          <ServerLoadChart history={history} metrics={metrics} timeZone={timeZone} />
          <ResourceUsageChart history={history} timeZone={timeZone} />
        </div>
      </section>
    </div>
  );
}
