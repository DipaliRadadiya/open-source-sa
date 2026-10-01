import Link from "@/components/ui/app-link";
import { useFormatter, useTranslations } from "next-intl";
import { scheduleWhen } from "@/lib/backups/schedule-time";
import { History, PlayCircle, Settings2, ShieldCheck } from "lucide-react";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { Card, CardContent } from "@/components/ui/card";
import { ActionIcon } from "@/components/ui/action-icon";
import { DomainText } from "@/components/ui/domain-text";
import { CardFact, CardFacts, CardList, CardListItem } from "@/components/data-table/card-list";
import { COVERAGE_STATE } from "@/components/backups/status-meta";

/**
 * The same list on a phone, where a table would scroll sideways and hide the
 * action button (as Services does below `lg`).
 */

export function CoverageCards({ rows, options = null, canManage, onSetUp, onBackUpNow, busyIds = [] }) {
  const t = useTranslations("backups.coverage");
  const tc = useTranslations("common");
  const format = useFormatter();

  // "Daily · 2:00 AM · keeps 7", dropping the hour a manual target lacks. The
  // timezone is named on the site's own backups page, not on every card.
  const scheduleFact = (target) => {
    const when = scheduleWhen(target, options, format);
    return [
      target.frequency_title ?? target.frequency,
      when?.minute ? t("minutePast", { minute: when.minute }) : when?.time,
      t("keeps", { count: target.retention_count }),
    ]
      .filter(Boolean)
      .join(" · ");
  };

  if (rows.length === 0) {
    return (
      <Card className="gap-0 py-0 shadow-sm">
        <CardContent className="py-10 text-center text-sm text-muted-foreground">
          {t("noMatches")}
        </CardContent>
      </Card>
    );
  }

  return (
    <CardList>
      {rows.map(({ application, target, state, lastBackup }) => {
        const meta = COVERAGE_STATE[state];
        const Icon = meta.icon;
        // Same rule as the table: an imminent run outranks tomorrow's slot.
        const next = target?.is_due
          ? t("runsShortly")
          : (target?.next_run_at_human ? t("nextRun", { when: target.next_run_at_human }) : null);

        return (
          <CardListItem key={application.id}>
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <Link
                  prefetch={false}
                  href={`/applications/${application.id}/backups`}
                  title={application.name}
                  className="block truncate font-medium underline-offset-4 hover:underline"
                >
                  {application.name}
                </Link>
                <DomainText domain={application.domain} className="text-xs text-muted-foreground" />
              </div>
              <Badge variant={meta.variant} className="shrink-0 gap-1.5 font-normal">
                <Icon className="size-3" />
                {t(`status.${state}`)}
              </Badge>
            </div>

            {/* An unconfigured site shows the same four facts, each saying "not set". */}
            <CardFacts>
              <CardFact
                label={t("columns.type")}
                className={cn(!target && "text-muted-foreground")}
                value={target ? (target.type_title ?? target.type) : t("placeholders.type")}
              />
              <CardFact
                label={t("columns.schedule")}
                className={cn(!target && "text-muted-foreground")}
                /* Includes the hour, same as the table. */
                value={target ? scheduleFact(target) : t("placeholders.schedule")}
              />
              <CardFact
                label={t("columns.storage")}
                className={cn(!target && "text-muted-foreground")}
                value={target?.storage_destination_name ?? t("placeholders.storage")}
              />
              <CardFact
                label={t("columns.lastRun")}
                className={cn(!target && "text-muted-foreground")}
              >
                <span className="block truncate">
                  {/* Same fallback as the table: a crashed run leaves
                      last_run_at unset, so "Never" would be wrong. */}
                  {target
                    ? (target.last_run_at_human ?? lastBackup?.created_at_human ?? t("neverRunShort"))
                    : t("placeholders.lastRun")}
                </span>
                {next ? (
                  <span className="block truncate text-xs text-muted-foreground">{next}</span>
                ) : null}
              </CardFact>
            </CardFacts>

            {/* Skipped only for an unprotected site without manage permission,
                which would have nothing but Set up. */}
            {state !== "unprotected" || canManage ? (
              <div className="mt-auto flex flex-wrap justify-end gap-2">
                {state === "unprotected" ? (
                  <Button size="sm" variant="outline" onClick={() => onSetUp(application.id)}>
                    <ShieldCheck className="size-4" />
                    {t("setUpShort")}
                  </Button>
                ) : (
                  <>
                    {canManage ? (
                      <ReasonTooltip reason={!target && !busyIds.includes(application.id) ? tc("needsBackupTarget") : null}>
                        <Button
                          size="sm"
                          variant="outline"
                          disabled={busyIds.includes(application.id) || !target}
                          onClick={() => onBackUpNow(application.id, application.name)}
                        >
                          <ActionIcon icon={PlayCircle} pending={busyIds.includes(application.id)} className="size-4" />
                          {t("runBackup")}
                        </Button>
                      </ReasonTooltip>
                    ) : null}
                    <Button size="sm" variant="ghost" asChild>
                      <Link href={`/backups/history?application=${application.id}`} prefetch={false}>
                        <History className="size-4" />
                        {t("viewBackups")}
                      </Link>
                    </Button>
                    {/* Matches the table's row menu. */}
                    <Button size="sm" variant="ghost" asChild>
                      <Link href={`/applications/${application.id}/backups`} prefetch={false}>
                        <Settings2 className="size-4" />
                        {t("manage")}
                      </Link>
                    </Button>
                  </>
                )}
              </div>
            ) : null}
          </CardListItem>
        );
      })}
    </CardList>
  );
}
