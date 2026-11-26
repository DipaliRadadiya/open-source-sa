"use client";

import { useTranslations, useFormatter } from "next-intl";
import { Plug, Activity, Gauge, Timer } from "lucide-react";
import { cn } from "@/lib/utils";
import {
  connectionsTone,
  slowQueriesTone,
  slowQueryRate,
  activityTone,
  activeQueries,
  STUCK_SECONDS,
} from "@/lib/databases/health";

const VERDICT_TONE = {
  normal: "text-muted-foreground",
  high: "text-warning",
  review: "text-destructive",
};

// Client component: icon components can't cross the server/client boundary.
export function EngineStatusCards({ status, processes = [] }) {
  const t = useTranslations("databases.monitor");
  const format = useFormatter();
  if (!status) return null;

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

  {
    const running = activeQueries(processes);
    const longRunning = running.filter((p) => (p?.time ?? 0) >= STUCK_SECONDS).length;
    cards.push({
      key: "threads",
      icon: Activity,
      label: t("runningQueries"),
      value: format.number(status.threads_running ?? running.length),
      sub: longRunning
        ? note(t("longRunningCount", { count: longRunning }))
        : verdict(activityTone(processes)),
    });
  }

  if (status.queries != null && status.uptime_seconds) {
    cards.push({
      key: "qps",
      icon: Gauge,
      label: t("qps"),
      value: format.number(status.queries / status.uptime_seconds, { maximumFractionDigits: 1 }),
      hint: t("avgShort"),
    });
  }

  if (status.slow_queries != null) {
    const rate = slowQueryRate(status);
    cards.push({
      key: "slow",
      icon: Timer,
      label: t("slowQueries"),
      value: format.number(status.slow_queries),
      hint: rate != null ? t("perHour", { rate: format.number(rate, { maximumFractionDigits: 1 }) }) : null,
      sub: verdict(slowQueriesTone(status)),
    });
  }

  if (cards.length === 0) return null;

  return (
    <div className={cn("grid gap-3 sm:grid-cols-2", cards.length === 4 ? "xl:grid-cols-4" : "lg:grid-cols-3")}>
      {cards.map(({ key, icon: Icon, label, value, hint, percent, sub }) => (
        <div key={key} className="rounded-2xl border border-border/70 bg-card px-4 py-3 shadow-e1">
          <div className="flex items-center gap-3">
            <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
              <Icon className="size-[18px]" aria-hidden />
            </span>
            <div className="min-w-0 flex-1">
              <p className="text-xs leading-tight text-muted-foreground">{label}</p>
              <p className="flex flex-wrap items-baseline gap-x-1.5">
                <span className="text-lg font-semibold tabular-nums">{value}</span>
                {hint ? <span className="text-xs text-muted-foreground tabular-nums">{hint}</span> : null}
              </p>
            </div>
            {sub ? <span className="shrink-0 rounded-full bg-muted px-2 py-0.5 text-xs">{sub}</span> : null}
          </div>
          {percent != null ? (
            <div className="mt-2.5 h-1 overflow-hidden rounded-full bg-primary/10" aria-hidden>
              <div className="h-full rounded-full bg-primary" style={{ width: `${Math.max(1, Math.min(100, percent))}%` }} />
            </div>
          ) : null}
        </div>
      ))}
    </div>
  );
}
