"use client";

import { useTranslations, useFormatter } from "next-intl";
import { Clock, Plug, Activity, Timer } from "lucide-react";
import { cn } from "@/lib/utils";
import { StatCard } from "@/components/ui/stat-card";
import {
  connectionsTone,
  slowQueriesTone,
  slowQueryRate,
  activityTone,
  recentlyRestarted,
  activeQueries,
  STUCK_SECONDS,
} from "@/lib/databases/health";

// Muted when fine, and the actual alarm colour when not.
const VERDICT_TONE = {
  normal: "text-muted-foreground",
  high: "text-warning",
  review: "text-destructive",
};

function secondsToHuman(seconds, t) {
  if (!seconds) return null;
  const days = Math.floor(seconds / 86400);
  if (days >= 1) return t("uptimeDays", { days });
  const hours = Math.floor(seconds / 3600);
  if (hours >= 1) return t("uptimeHours", { hours });
  return t("uptimeMinutes", { minutes: Math.max(1, Math.floor(seconds / 60)) });
}

/**
 * The four numbers worth knowing about a database engine.
 *
 * Uses the shared StatCard rather than a local one — the same component the
 * server dashboard and disk cleaner use. This page had hand-rolled a flatter
 * copy (label over number, no icon, no bar), which is precisely the drift that
 * component exists to prevent.
 *
 * Client, not server: StatCard takes an `icon` component, and a component
 * reference cannot cross the server/client boundary. The dashboard's stat row
 * is a client component for the same reason.
 *
 * Connections carries a usage bar because the number alone means nothing: 42 is
 * fine out of 151 and a crisis out of 50, and the bar turns warning then
 * destructive on its own as the ceiling approaches. Mongo returns nulls for the
 * SQL-only fields, so anything missing is left out rather than shown as zero.
 */
export function EngineStatusCards({ status, processes = [] }) {
  const t = useTranslations("databases.monitor");
  const format = useFormatter();
  if (!status) return null;

  // "Normal / High / Needs review" under each number. A measured value the
  // reader has to interpret is only half a metric — the whole point of this
  // page is that you should not need to know what 42 of 151 means.
  //
  // Coloured by its own verdict and given weight, so the helper line reads as
  // the card's conclusion rather than as a caption you skip. StatCard renders
  // whatever node it is handed, so this needs no change to the shared card.
  const verdict = (tone) => (
    <span className={cn("font-medium", VERDICT_TONE[tone])}>{t(`verdict.${tone}`)}</span>
  );
  const note = (text) => <span className="font-medium text-muted-foreground">{text}</span>;

  const cards = [];

  if (status.connections != null) {
    const max = status.max_connections;
    cards.push({
      key: "connections",
      icon: Plug,
      label: t("connections"),
      value: format.number(status.connections),
      hint: max ? t("ofMaxShort", { max: format.number(max) }) : null,
      percent: max ? (status.connections / max) * 100 : null,
      sub: max ? verdict(connectionsTone(status)) : null,
    });
  }

  if (status.threads_running != null) {
    const longRunning = activeQueries(processes).filter(
      (p) => (p?.time ?? 0) >= STUCK_SECONDS,
    ).length;
    cards.push({
      key: "threads",
      icon: Activity,
      label: t("runningQueries"),
      value: format.number(status.threads_running),
      // The count of long-running ones beats a verdict word here: "1
      // long-running" is the fact, "Needs review" is only the conclusion.
      // Judged by the longest query, not by the total — three threads is
      // unremarkable until one has been going for two minutes.
      sub: longRunning
        ? note(t("longRunningCount", { count: longRunning }))
        : verdict(activityTone(processes)),
    });
  }

  if (status.slow_queries != null) {
    const rate = slowQueryRate(status);
    cards.push({
      key: "slow",
      icon: Timer,
      label: t("slowQueries"),
      value: format.number(status.slow_queries),
      // A cumulative counter, not a current reading. Against uptime it becomes
      // a per-hour rate, which is the only form of it worth judging.
      hint: rate != null ? t("perHour", { rate: format.number(rate, { maximumFractionDigits: 1 }) }) : null,
      sub: verdict(slowQueriesTone(status)),
    });
  }

  const uptime = secondsToHuman(status.uptime_seconds, t);
  if (uptime) {
    cards.push({
      key: "uptime",
      icon: Clock,
      label: t("uptime"),
      value: uptime,
      sub: note(recentlyRestarted(status) ? t("verdict.restarted") : t("sinceRestart")),
    });
  }

  if (cards.length === 0) return null;

  return (
    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      {cards.map(({ key, ...card }) => (
        <StatCard key={key} hasSub {...card} />
      ))}
    </div>
  );
}
