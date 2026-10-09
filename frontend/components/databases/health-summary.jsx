"use client";

import { useTranslations, useFormatter } from "next-intl";
import { CircleCheck, CircleHelp, TriangleAlert, CircleAlert, Clock, Database, Info } from "lucide-react";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { cn } from "@/lib/utils";
import { assessHealth } from "@/lib/databases/health";
import { Card, CardContent } from "@/components/ui/card";

const CHIP = "flex items-center gap-1.5 rounded-lg border border-border/70 bg-card px-2 py-1";

// Worst first, so the lead line is the most important issue.
const RANK = { normal: 0, high: 1, review: 2 };

const TONE = {
  normal: {
    card: "border-success/30 bg-success/5",
    tile: "bg-success/10 text-success",
    icon: CircleCheck,
  },
  high: {
    card: "border-warning/40 bg-warning/5",
    tile: "bg-warning/15 text-warning",
    icon: TriangleAlert,
  },
  review: {
    card: "border-destructive/30 bg-destructive/5",
    tile: "bg-destructive/10 text-destructive",
    icon: CircleAlert,
  },
};

const ISSUE_TEXT = "text-sm";

export function HealthSummary({ engine, status, processes = [] }) {
  const t = useTranslations("databases.monitor.health");
  const tEngines = useTranslations("databases.engines");
  const format = useFormatter();
  const { tone, issues, recentlyRestarted } = assessHealth({ status, processes });

  // No status means the check did not answer: say so instead of "Healthy".
  if (!status) {
    return (
      <Card className="gap-0 overflow-hidden py-0">
        <div className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
          <div className="flex items-center gap-3">
            <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-muted text-muted-foreground">
              <CircleHelp className="size-6" aria-hidden />
            </span>
            <div className="min-w-0 space-y-0.5">
              <p className="font-semibold">{t("unknownTitle")}</p>
              <p className="text-sm text-muted-foreground">
                {t("unknownBody", { engine: engine?.engine ? tEngines(engine.engine) : "" })}
              </p>
            </div>
          </div>
          <RefreshButton />
        </div>
      </Card>
    );
  }
  const styles = TONE[tone];
  const Icon = styles.icon;

  // The worst issue sits beside the verdict; the rest go in the strip below.
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
      {/* Slim: a verdict line, not a hero. */}
      <div className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
        <div className="flex items-center gap-3">
          <span
            className={cn(
              "flex size-9 shrink-0 items-center justify-center rounded-xl",
              styles.tile,
            )}
          >
            <Icon className="size-5" aria-hidden />
          </span>
          <div className="min-w-0">
            <p className="text-[15px] font-semibold tracking-tight">
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

        {/* Engine, state and uptime as three matching white chips: a green "Running" pill
            vanished on the green healthy card (Krishna, 7 Oct). */}
        <div className="flex flex-wrap items-center gap-2 text-xs">
          <span className={CHIP}>
            <Database className="size-3.5 text-muted-foreground" aria-hidden />
            <span className="font-medium">{engine?.engine ? tEngines(engine.engine) : null}</span>
            {engine?.version ? (
              <span className="font-mono text-muted-foreground">{engine.version}</span>
            ) : null}
          </span>
          <span className={CHIP}>
            <span className="size-1.5 rounded-full bg-success" aria-hidden />
            <span className="font-medium text-[color-mix(in_oklch,var(--success)_80%,var(--foreground))] dark:text-success">
              {t("running")}
            </span>
          </span>
          {uptime ? (
            <span className={CHIP}>
              <Clock className="size-3.5 text-muted-foreground" aria-hidden />
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
