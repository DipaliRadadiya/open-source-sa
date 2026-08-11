"use client";

import { useTranslations, useFormatter } from "next-intl";
import { CircleAlert } from "lucide-react";
import { cn } from "@/lib/utils";
import { clockFormatter } from "@/lib/format/time";
import { useLiveMetrics } from "@/components/server-dashboard/use-live-metrics";
import { StatCards } from "@/components/server-dashboard/stat-cards";
import { ServerLoadChart } from "@/components/server-dashboard/server-load-chart";
import { ResourceUsageChart } from "@/components/server-dashboard/resource-usage-chart";
import { NetworkIoChart } from "@/components/server-dashboard/network-io-chart";
import { DiskIoChart } from "@/components/server-dashboard/disk-io-chart";

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
    <div role="status" className="flex flex-wrap items-center gap-x-3 gap-y-1">
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

export function LiveMetricsSection({ timeZone }) {
  const { metrics, series, failed, updatedAt, ratesReady } = useLiveMetrics();
  // Everything on screen is last-known, not current — the charts have to say
  // so as loudly as the stat cards do.
  const stale = failed && Boolean(metrics);

  return (
    <div className="space-y-4">
      <LiveStatus failed={failed} updatedAt={updatedAt} timeZone={timeZone} />
      <StatCards metrics={metrics} stale={stale} ratesReady={ratesReady} />
      {/* Row one asks "is the machine struggling", row two "what is it
          moving". All four read the same poll, so nothing here costs an extra
          request — and no metric appears on two charts. */}
      <div className="grid gap-4 lg:grid-cols-2">
        <ServerLoadChart
          series={series}
          metrics={metrics}
          timeZone={timeZone}
          stale={stale}
        />
        <ResourceUsageChart
          series={series}
          metrics={metrics}
          timeZone={timeZone}
          stale={stale}
          ratesReady={ratesReady}
        />
      </div>
      <div className="grid gap-4 lg:grid-cols-2">
        <NetworkIoChart
          series={series}
          metrics={metrics}
          timeZone={timeZone}
          stale={stale}
        />
        <DiskIoChart series={series} metrics={metrics} timeZone={timeZone} stale={stale} />
      </div>
    </div>
  );
}
