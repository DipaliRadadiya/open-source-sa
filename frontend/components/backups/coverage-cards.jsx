import Link from "@/components/ui/app-link";
import { useFormatter, useTranslations } from "next-intl";
import { scheduleWhen } from "@/lib/backups/schedule-time";
import { frequencyLabel } from "@/lib/backups/frequency";
import { BACKUP_IN_FLIGHT } from "@/lib/schemas/backup";
import { BackupStatusBadge } from "@/components/backups/backup-status-badge";
import { PlayCircle, ShieldCheck, SearchX, Archive } from "lucide-react";
import { EmptyState } from "@/components/data-table/empty-state";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { ActionIcon } from "@/components/ui/action-icon";
import { CardList } from "@/components/data-table/card-list";
import { COVERAGE_STATE } from "@/components/backups/status-meta";
import { HoverHint } from "@/components/ui/hover-hint";
import { CoverageCard, CoverageCardActions, CoverageFact, NotSetUpList, NotSetUpRow } from "@/components/backups/coverage-card-parts";

// Phone and tablet layout: a table would scroll sideways and hide the action button.

export function CoverageCards({ rows, options = null, canManage, onSetUp, onBackUpNow, busyIds = [], restoringId = null }) {
  const t = useTranslations("backups.coverage");
  const tb = useTranslations("backups");
  const ta = useTranslations("backups.application");
  const th = useTranslations("backups.history");
  const tn = useTranslations("applications.backups");
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

  const configured = rows.filter((row) => row.target);
  const notSetUp = rows.filter((row) => !row.target);

  const setUpButton = (application) => (
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
  );

  return (
    <div className="space-y-3">
      {configured.length ? (
        <CardList>
          {configured.map(({ application, target, state, lastBackup }) => {
            const meta = COVERAGE_STATE[state];
            const Icon = meta.icon;
            // Same rule as the table: an imminent run outranks tomorrow's slot.
            const next = target.is_due
              ? t("runsShortly")
              : (target.next_run_at_human ? t("nextRun", { when: target.next_run_at_human }) : null);
            const busy = busyIds.includes(application.id);

            return (
              <CoverageCard
                key={application.id}
                title={
                  <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                    <HoverHint content={application.name}><Link
                      prefetch={false}
                      href={`/applications/${application.id}/backups`}
                      className="max-w-full truncate font-medium underline-offset-4 hover:underline"
                    >
                      {application.name}
                    </Link></HoverHint>
                    {application.is_staging ? <Badge variant="secondary" className="shrink-0 text-xs">{t("staging")}</Badge> : null}
                  </div>
                }
                subtitle={application.domain}
                // No badge where the card already says it, as in the table: "Manual only" is on
                // the Schedule line.
                badge={state === "paused" || state === "unprotected" ? null : (
                  <Badge variant={meta.variant} className="gap-1.5 font-normal">
                    <Icon className="size-3" />
                    {t(`status.${state}`)}
                  </Badge>
                )}
                actions={
                  // Viewers see the actions too, disabled with the reason.
                  state === "unprotected" ? setUpButton(application) : (
                    <CoverageCardActions
                      historyHref={`/backups/history?application=${application.id}`}
                      settingsHref={`/applications/${application.id}/backups`}
                      run={
                        <ReasonTooltip reason={!canManage ? th("noPermission") : restoringId === application.id ? ta("restoreRunning") : null}>
                          <Button
                            size="sm"
                            variant="outline"
                            disabled={!canManage || busy || restoringId === application.id}
                            onClick={() => onBackUpNow(application.id, application.name)}
                          >
                            <ActionIcon icon={PlayCircle} pending={busy} className="size-4" />
                            {t("runBackup")}
                          </Button>
                        </ReasonTooltip>
                      }
                    />
                  )
                }
              >
                <CoverageFact label={t("columns.typeShort")}>{target.type_title ?? target.type}</CoverageFact>
                {/* Includes the hour, same as the table; amber when paused, since the card has
                    no "Manual only" badge. */}
                <CoverageFact label={t("columns.schedule")} className={state === "paused" ? "[&>dd]:font-medium [&>dd]:text-warning" : undefined}>
                  {scheduleFact(target)}
                </CoverageFact>
                <CoverageFact label={t("columns.storage")}>
                  <span className="break-words">{target.storage_destination_name ?? t("placeholders.storage")}</span>
                </CoverageFact>
                <CoverageFact label={t("columns.lastRun")}>
                  {/* The table's status dot, as a badge: without it a run in progress or a failed
                      one read as an ordinary "Last run". */}
                  {lastBackup && (BACKUP_IN_FLIGHT.includes(lastBackup.status) || lastBackup.status === "failed") ? (
                    <span className="mb-1 block">
                      <BackupStatusBadge backup={lastBackup} />
                    </span>
                  ) : null}
                  {/* Same fallback as the table: a crashed run leaves last_run_at unset, so not "Never". */}
                  <span className="block">{lastBackup?.created_at_human ?? target.last_backup_at_human ?? t("neverRunShort")}</span>
                  {next ? <span className="block text-xs text-muted-foreground">{next}</span> : null}
                </CoverageFact>
              </CoverageCard>
            );
          })}
        </CardList>
      ) : null}

      {notSetUp.length ? (
        <NotSetUpList
          title={<>{tn("notSetUp")}<span className="ms-2 font-normal tabular-nums text-muted-foreground">{notSetUp.length}</span></>}
          description={t("notSetUpListHint")}
        >
          {notSetUp.map(({ application }) => (
            <NotSetUpRow
              key={application.id}
              name={application.name}
              href={`/applications/${application.id}/backups`}
              subtitle={application.domain}
              action={setUpButton(application)}
            />
          ))}
        </NotSetUpList>
      ) : null}
    </div>
  );
}
