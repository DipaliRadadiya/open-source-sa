"use client";

import { useEffect, useRef, useState } from "react";
import dynamic from "next/dynamic";
import { useTranslations, useFormatter } from "next-intl";
import { CircleAlert, History, Radio } from "lucide-react";
import { cn } from "@/lib/utils";
import { clockFormatter } from "@/lib/format/time";
import { useLiveMetrics } from "@/components/dashboard/use-live-metrics";
import { StatCards } from "@/components/dashboard/stat-cards";
import { ChartCardSkeleton } from "@/components/dashboard/chart-card-skeleton";

/**
 * Charts load after the page: Recharts is ~400 KB and this is the login
 * landing screen. `ssr: false` because none can render real content on the
 * server (I/O needs a second poll sample; history is client-polled).
 */
const chart = (load) => dynamic(load, { ssr: false, loading: ChartCardSkeleton });

const ServerLoadChart = chart(() =>
  import("@/components/dashboard/server-load-chart").then((m) => m.ServerLoadChart),
);
const ResourceUsageChart = chart(() =>
  import("@/components/dashboard/resource-usage-chart").then((m) => m.ResourceUsageChart),
);
const NetworkIoChart = chart(() =>
  import("@/components/dashboard/network-io-chart").then((m) => m.NetworkIoChart),
);
const DiskIoChart = chart(() =>
  import("@/components/dashboard/disk-io-chart").then((m) => m.DiskIoChart),
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
          "inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium",
          failed
            ? "border-destructive/30 bg-destructive/10 text-destructive"
            : "border-success/30 bg-success/10 text-success",
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

/**
 * Names one of the two clocks on this page (live vs 24h). h2 under the page
 * h1, so the cards inside use h3.
 */
function SectionHeading({ icon: Icon, title, children }) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b pb-2">
      <h2 className="flex items-center gap-2 text-sm font-semibold">
        <Icon className="size-4 text-muted-foreground" />
        {title}
      </h2>
      {children}
    </div>
  );
}

export function LiveMetricsSection({ timeZone, history = [] }) {
  const t = useTranslations("serverDashboard");
  const { metrics, series, failed, reason, updatedAt, ratesReady } = useLiveMetrics();
  // Everything on screen is last-known, not current; the charts must show it too.
  const stale = failed && Boolean(metrics);

  return (
    <div className="space-y-6">
      {/* Grouped by clock, not subject: the 3s live poll vs the five-minute
          24h collector. */}
      <ConnectionAnnouncement failed={failed} />

      <section className="space-y-4">
        {/* The Live pill is this section's status, so it sits in the heading row. */}
        <SectionHeading icon={Radio} title={t("liveLabel")}>
          <LiveStatus failed={failed} reason={reason} updatedAt={updatedAt} timeZone={timeZone} />
        </SectionHeading>
        <StatCards metrics={metrics} stale={stale} ratesReady={ratesReady} />
        <div className="grid gap-4 lg:grid-cols-2">
          <NetworkIoChart
            series={series}
            metrics={metrics}
            timeZone={timeZone}
            stale={stale}
          />
          <DiskIoChart series={series} metrics={metrics} timeZone={timeZone} stale={stale} />
        </div>
      </section>

      <section className="space-y-4">
        <SectionHeading icon={History} title={t("historyLabel")} />
        <div className="grid gap-4 lg:grid-cols-2">
          <ServerLoadChart history={history} metrics={metrics} timeZone={timeZone} />
          <ResourceUsageChart history={history} timeZone={timeZone} />
        </div>
      </section>
    </div>
  );
}
