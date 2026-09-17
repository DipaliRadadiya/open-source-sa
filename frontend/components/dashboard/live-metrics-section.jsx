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
 * The four charts are loaded after the page, not with it.
 *
 * Recharts is around 400 KB and this is the screen login lands on, so
 * importing it statically made the numbers people actually came for wait for a
 * drawing library. Split out, the stat cards and the live badge are readable
 * immediately and the charts fill in behind them.
 *
 * `ssr: false` because none of the four can render anything real on the
 * server: the two I/O charts need a second poll sample before they have a
 * line at all, and the two history charts are inside a client-polled section.
 * Server-rendering markup that is about to be replaced buys nothing and costs
 * the render.
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

function LiveStatus({ failed, updatedAt, timeZone }) {
  const t = useTranslations("serverDashboard");
  const format = useFormatter();
  // timeZoneName here only — the axes stay uncluttered, but the "Updated" label
  // has to say which clock it means.
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
    </div>
  );
}

/**
 * Names one of the two clocks on this page.
 *
 * "Last 24 hours" used to be a lone 12px muted line with a `pt-2` on it, which
 * labelled the block below but left the block ABOVE it unnamed — so the page
 * read as some charts, then a section. Both groups get the same heading now;
 * the rule under it is what makes them read as regions rather than as captions.
 *
 * h2 under the page h1, which is why the cards inside dropped to h3.
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
  const { metrics, series, failed, updatedAt, ratesReady } = useLiveMetrics();
  // Everything on screen is last-known, not current — the charts have to say
  // so as loudly as the stat cards do.
  const stale = failed && Boolean(metrics);

  return (
    // space-y-6 matches the page's own rhythm, so a section break here is worth
    // exactly as much as the break between this and the Processes card.
    <div className="space-y-6">
      {/* Grouped by clock, not by subject. Everything under the Live badge is
          the 3s poll; everything under the 24h label is the five-minute
          collector. Mixing the two under one "Live" badge is exactly the
          question this page kept being asked. */}
      <ConnectionAnnouncement failed={failed} />

      <section className="space-y-4">
        {/* The Live pill sits IN the heading row rather than above it — it is
            this section's status, and as a floating row it belonged to nothing
            in particular. */}
        <SectionHeading icon={Radio} title={t("liveLabel")}>
          <LiveStatus failed={failed} updatedAt={updatedAt} timeZone={timeZone} />
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
