"use client";

import { useTranslations, useFormatter } from "next-intl";
import { CircleCheck, TriangleAlert, CircleAlert, Clock, Info } from "lucide-react";
import { cn } from "@/lib/utils";
import { assessHealth } from "@/lib/databases/health";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";

// Worst first, so the lead line is the thing that matters most.
const RANK = { normal: 0, high: 1, review: 2 };

const TONE = {
  normal: {
    card: "border-success/30",
    tile: "bg-success/10 text-success",
    icon: CircleCheck,
  },
  high: {
    card: "border-warning/40",
    tile: "bg-warning/15 text-warning",
    icon: TriangleAlert,
  },
  review: {
    card: "border-destructive/30",
    tile: "bg-destructive/10 text-destructive",
    icon: CircleAlert,
  },
};

const ISSUE_TEXT = "text-sm";

/**
 * The verdict, before any numbers.
 *
 * Someone opening a page called "Database health" is asking one question, and
 * a row of counters does not answer it — you have to already know that 42 of
 * 151 connections is fine and that a two-minute query is not. This says
 * healthy or not, and then says exactly why.
 */
export function HealthSummary({ engine, status, processes = [] }) {
  const t = useTranslations("databases.monitor.health");
  const format = useFormatter();
  const { tone, issues, recentlyRestarted } = assessHealth({ status, processes });
  const styles = TONE[tone];
  const Icon = styles.icon;

  // The worst issue is stated next to the verdict, not left in a list below
  // it: "Needs attention / 1 thing to look at" made you read further to learn
  // what the thing was. Anything else stays in the strip underneath.
  const ranked = [...issues].sort((a, b) => RANK[b.tone] - RANK[a.tone]);
  const [lead, ...rest] = ranked;
  const issueText = (issue) =>
    t(`issues.${issue.key}`, {
      count: issue.count ?? 0,
      percent: issue.percent ?? 0,
      rate: format.number(issue.rate ?? 0),
    });

  const uptime = uptimeLabel(status?.uptime_seconds, t);

  return (
    <Card className={cn("gap-0 overflow-hidden py-0", styles.card)}>
      <div className="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
        <div className="flex items-center gap-3">
          <span
            className={cn(
              "flex size-11 shrink-0 items-center justify-center rounded-xl",
              styles.tile,
            )}
          >
            <Icon className="size-6" aria-hidden />
          </span>
          <div className="min-w-0">
            <p className="text-lg font-semibold tracking-tight">
              {tone === "normal" ? t("healthy") : t("attention")}
            </p>
            <p
              className={cn(
                "text-sm",
                lead
                  ? lead.tone === "review"
                    ? "text-destructive"
                    : "text-warning"
                  : "text-muted-foreground",
              )}
            >
              {lead ? issueText(lead) : t("healthyBody")}
            </p>
            {rest.length ? (
              <p className="text-xs text-muted-foreground">
                {t("issueCount", { count: rest.length })}
              </p>
            ) : null}
          </div>
        </div>

        {/* The facts you would otherwise scroll for: which engine, which
            version, is it up, how long. */}
        <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
          <span className="flex items-center gap-2">
            <span className="font-medium">{engine?.engine}</span>
            {engine?.version ? (
              <span className="font-mono text-xs text-muted-foreground">
                {engine.version}
              </span>
            ) : null}
          </span>
          <Badge variant="success" className="font-normal">
            {t("running")}
          </Badge>
          {uptime ? (
            <span className="flex items-center gap-1.5 text-muted-foreground">
              <Clock className="size-3.5" />
              {uptime}
            </span>
          ) : null}
        </div>
      </div>

      {rest.length || recentlyRestarted ? (
        <CardContent className="space-y-1.5 border-t bg-muted/30 px-5 py-3">
          {rest.map((issue) => (
            <p
              key={issue.key}
              className={cn(
                ISSUE_TEXT,
                "flex items-start gap-2",
                issue.tone === "review" ? "text-destructive" : "text-warning",
              )}
            >
              {issue.tone === "review" ? (
                <CircleAlert className="mt-0.5 size-4 shrink-0" />
              ) : (
                <TriangleAlert className="mt-0.5 size-4 shrink-0" />
              )}
              <span>
                {issueText(issue)}
              </span>
            </p>
          ))}

          {recentlyRestarted ? (
            <p className={cn(ISSUE_TEXT, "flex items-start gap-2 text-muted-foreground")}>
              <Info className="mt-0.5 size-4 shrink-0" />
              <span>{t("issues.recentlyRestarted")}</span>
            </p>
          ) : null}
        </CardContent>
      ) : null}
    </Card>
  );
}

function uptimeLabel(seconds, t) {
  if (!seconds) return null;
  const days = Math.floor(seconds / 86400);
  if (days >= 1) return t("uptimeDays", { days });
  const hours = Math.floor(seconds / 3600);
  if (hours >= 1) return t("uptimeHours", { hours });
  return t("uptimeMinutes", { minutes: Math.max(1, Math.floor(seconds / 60)) });
}
