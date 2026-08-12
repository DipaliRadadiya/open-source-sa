"use client";

import Link from "next/link";
import { useTranslations } from "next-intl";
import { History, PlayCircle, ShieldCheck } from "lucide-react";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { COVERAGE_STATE } from "@/components/backups/status-meta";

/**
 * The same list on a phone.
 *
 * A table would scroll sideways here and put the one button you came for
 * off-screen with nothing hinting at a swipe — the same reason Services keeps
 * a card layout below `lg`.
 */

export function CoverageCards({ rows, canManage, onSetUp, onBackUpNow, busyId }) {
  const t = useTranslations("backups.coverage");

  if (rows.length === 0) {
    return (
      <Card className="shadow-sm">
        <CardContent className="py-10 text-center text-sm text-muted-foreground">
          {t("noMatches")}
        </CardContent>
      </Card>
    );
  }

  return (
    <div className="space-y-3">
      {rows.map(({ application, target, state }) => {
        const meta = COVERAGE_STATE[state];
        const Icon = meta.icon;
        // Same rule as the table: an imminent run outranks tomorrow's slot.
        const next = target?.is_due
          ? t("runsShortly")
          : (target?.next_run_at_human ? t("nextRun", { when: target.next_run_at_human }) : null);

        return (
          <Card key={application.id} className="gap-0 py-0 shadow-sm">
            <CardContent className="space-y-3 p-4">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <Link
                    prefetch={false}
                    href={`/applications/${application.id}/backups`}
                    className="block truncate font-medium underline-offset-4 hover:underline"
                  >
                    {application.name}
                  </Link>
                  <p className="truncate text-xs text-muted-foreground">{application.domain}</p>
                </div>
                <Badge variant={meta.variant} className="shrink-0 gap-1.5 font-normal">
                  <Icon className="size-3" />
                  {t(`status.${state}`)}
                </Badge>
              </div>

              {/* An unconfigured site shows the same four facts as a
                  configured one, each saying it is not set rather than being
                  omitted — so the card never looks half-rendered. */}
              <dl className="grid grid-cols-2 gap-x-4 gap-y-2 border-t pt-3">
                <Fact
                  label={t("columns.type")}
                  value={target ? (target.type_title ?? target.type) : t("placeholders.type")}
                  muted={!target}
                />
                <Fact
                  label={t("columns.schedule")}
                  value={
                    target
                      ? `${target.frequency_title ?? target.frequency} · ${t("keeps", { count: target.retention_count })}`
                      : t("placeholders.schedule")
                  }
                  muted={!target}
                />
                <Fact
                  label={t("columns.storage")}
                  value={target?.storage_destination_name ?? t("placeholders.storage")}
                  muted={!target}
                />
                <Fact
                  label={t("columns.lastRun")}
                  value={
                    target
                      ? (target.last_run_at_human ?? t("neverRunShort"))
                      : t("placeholders.lastRun")
                  }
                  hint={next}
                  muted={!target}
                />
              </dl>

              {canManage || !target ? (
                <div className="flex justify-end gap-2">
                  {state === "unprotected" ? (
                    canManage ? (
                      <Button size="sm" variant="outline" onClick={() => onSetUp(application.id)}>
                        <ShieldCheck className="size-4" />
                        {t("setUpShort")}
                      </Button>
                    ) : null
                  ) : (
                    <>
                      {canManage ? (
                        <Button
                          size="sm"
                          variant="outline"
                          disabled={busyId === application.id || !target}
                          onClick={() => onBackUpNow(application.id, application.name)}
                        >
                          <PlayCircle className="size-4" />
                          {t("runBackup")}
                        </Button>
                      ) : null}
                      <Button size="sm" variant="ghost" asChild>
                        <Link href={`/backups/history?application=${application.id}`}>
                          <History className="size-4" />
                          {t("viewBackups")}
                        </Link>
                      </Button>
                    </>
                  )}
                </div>
              ) : null}
            </CardContent>
          </Card>
        );
      })}
    </div>
  );
}

function Fact({ label, value, hint, muted }) {
  return (
    <div className="min-w-0">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className={cn("truncate text-sm", muted && "text-muted-foreground/70")}>{value}</dd>
      {hint ? <dd className="truncate text-xs text-muted-foreground">{hint}</dd> : null}
    </div>
  );
}
