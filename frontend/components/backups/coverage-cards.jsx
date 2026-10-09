import Link from "@/components/ui/app-link";
import { useFormatter, useTranslations } from "next-intl";
import { scheduleWhen } from "@/lib/backups/schedule-time";
import { frequencyLabel } from "@/lib/backups/frequency";
import { BACKUP_IN_FLIGHT } from "@/lib/schemas/backup";
import { BackupStatusBadge } from "@/components/backups/backup-status-badge";
import { History, PlayCircle, Settings2, ShieldCheck, SearchX, Archive } from "lucide-react";
import { EmptyState } from "@/components/data-table/empty-state";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { ActionIcon } from "@/components/ui/action-icon";
import { DomainText } from "@/components/ui/domain-text";
import { CardFact, CardFacts, CardList, CardListItem } from "@/components/data-table/card-list";
import { COVERAGE_STATE } from "@/components/backups/status-meta";

// Phone layout: a table would scroll sideways and hide the action button.

export function CoverageCards({ rows, options = null, canManage, onSetUp, onBackUpNow, busyIds = [], restoringId = null }) {
  const t = useTranslations("backups.coverage");
  const tb = useTranslations("backups");
  const ta = useTranslations("backups.application");
  const th = useTranslations("backups.history");
  const format = useFormatter();

  // "Daily · 2:00 AM · keeps 7"; the timezone is named on the site's backups page instead.
  const scheduleFact = (target) => {
    const when = scheduleWhen(target, options, format);
    return [
      frequencyLabel(target, (frequency) => tb("pausedFrequency", { frequency })),
      when?.minute ? t("minutePast", { minute: when.minute }) : when?.time,
      t("keeps", { count: target.retention_count }),
    ]
      .filter(Boolean)
      .join(" · ");
  };

  if (rows.length === 0) {
    return (
      <EmptyState icon={SearchX} subject={Archive} title={t("noMatches")} />
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
              {/* No badge where the card already says it, as in the table: "Manual only"
                  is on the Schedule line, "None" is the "Not set up" line. */}
              {state === "paused" || state === "unprotected" ? null : (
                <Badge variant={meta.variant} className="shrink-0 gap-1.5 font-normal">
                  <Icon className="size-3" />
                  {t(`status.${state}`)}
                </Badge>
              )}
            </div>

            {/* Not set up: one line and the button, as in the table. Four facts each
                saying "not set" made 22 tall, identical cards. */}
            {!target ? (
              <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t pt-3">
                <p className="min-w-48 flex-1 text-sm text-muted-foreground">{t("notSetUpLine")}</p>
                <Button
                  size="sm"
                  variant="outline"
                  disabled={!canManage}
                  disabledReason={canManage ? null : th("noPermission")}
                  onClick={() => onSetUp(application.id)}
                >
                  <ShieldCheck className="size-4" />
                  {t("setUpShort")}
                </Button>
              </div>
            ) : (
            <>
              <CardFacts>
                <CardFact label={t("columns.type")} value={target.type_title ?? target.type} />
                <CardFact
                  label={t("columns.schedule")}
                  /* Includes the hour, same as the table; amber when paused, since the
                     card has no "Manual only" badge. */
                  className={state === "paused" ? "[&>dd]:font-medium [&>dd]:text-warning" : undefined}
                  value={scheduleFact(target)}
                />
                <CardFact label={t("columns.storage")} value={target.storage_destination_name ?? t("placeholders.storage")} />
                <CardFact label={t("columns.lastRun")}>
                  {/* The table's status dot, as a badge: without it a run in progress or a
                      failed one read as an ordinary "Last run". */}
                  {lastBackup && (BACKUP_IN_FLIGHT.includes(lastBackup.status) || lastBackup.status === "failed") ? (
                    <span className="mb-1 block">
                      <BackupStatusBadge backup={lastBackup} />
                    </span>
                  ) : null}
                  <span className="block truncate">
                    {/* Same fallback as the table: a crashed run leaves last_run_at unset, so not "Never". */}
                    {lastBackup?.created_at_human ?? target.last_backup_at_human ?? t("neverRunShort")}
                  </span>
                  {next ? (
                    <span className="block truncate text-xs text-muted-foreground">{next}</span>
                  ) : null}
                </CardFact>
              </CardFacts>

              {/* Viewers see the actions too, disabled with the reason. */}
              <div className="mt-auto flex flex-wrap justify-end gap-2">
                  {state === "unprotected" ? (
                    <Button
                      size="sm"
                      variant="outline"
                      disabled={!canManage}
                      disabledReason={canManage ? null : th("noPermission")}
                      onClick={() => onSetUp(application.id)}
                    >
                      <ShieldCheck className="size-4" />
                      {t("setUpShort")}
                    </Button>
                  ) : (
                    <>
                      <ReasonTooltip
                        reason={
                          !canManage
                            ? th("noPermission")
                            : restoringId === application.id
                              ? ta("restoreRunning")
                              : null
                        }
                      >
                        <Button
                          size="sm"
                          variant="outline"
                          disabled={!canManage || busyIds.includes(application.id) || restoringId === application.id}
                          onClick={() => onBackUpNow(application.id, application.name)}
                        >
                          <ActionIcon icon={PlayCircle} pending={busyIds.includes(application.id)} className="size-4" />
                          {t("runBackup")}
                        </Button>
                      </ReasonTooltip>
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
            </>
            )}
          </CardListItem>
        );
      })}
    </CardList>
  );
}
