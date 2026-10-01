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

// Muted when fine, alarm colour when not.
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

// Client component: StatCard's `icon` cannot cross the server/client boundary.
// Mongo returns nulls for SQL-only fields; those are omitted, not shown as zero.
export function EngineStatusCards({ status, processes = [] }) {
  const t = useTranslations("databases.monitor");
  const format = useFormatter();
  if (!status) return null;

  // "Normal / High / Needs review" under each number, coloured by its verdict
  // so it reads as the card's conclusion.
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
      // The long-running count beats a verdict word. Judged by the longest
      // query, not the total thread count.
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
      // A cumulative counter; shown as a per-hour rate against uptime.
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
