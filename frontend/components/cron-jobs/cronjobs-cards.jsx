import { useTranslations } from "next-intl";
import { CardFactGrid, CardFactStacked, CardList, CardListItem } from "@/components/data-table/card-list";
import { CronjobActiveSwitch } from "@/components/cron-jobs/cronjob-active-switch";
import { CronjobRowActions } from "@/components/cron-jobs/cronjob-row-actions";
import { CronjobPhpNote } from "@/components/cron-jobs/cronjob-php-note";
import {
  CronjobName,
  CronjobNextRun,
  CronjobRunAs,
  CronjobSchedule,
} from "@/components/cron-jobs/cronjobs-table";
import { CronjobLastRun } from "@/components/cron-jobs/cronjob-last-run";

// Narrow-screen layout: the command gets the wide line under the name.
export function CronjobsCards({
  runAs,
  prevPage = null,
  canViewLogs = false,
  jobs,
  canManage = false,
  schedulePresets = [],
  commandPresets = [],
  applications = [],
  placeholder,
  timezone,
  onDuplicate,
  historySupported = false,
}) {
  const t = useTranslations("cronJobs");

  return (
    <CardList>
      {jobs.map((job) => (
        <CardListItem key={job.id}>
          <div className="flex items-start justify-between gap-2">
            <div className="min-w-0">
              <CronjobName job={job} />
              {/* Wraps rather than truncating so the command is identifiable;
                  capped so a long one cannot push the actions off screen. */}
              <p className="line-clamp-3 font-mono text-xs break-all text-muted-foreground">
                {job.command}
              </p>
              <CronjobPhpNote job={job} applications={applications} className="mt-1" />
            </div>
            <div className="-me-2 -mt-1 shrink-0">
              <CronjobRowActions
                job={job}
                schedulePresets={schedulePresets}
                commandPresets={commandPresets}
                applications={applications}
                placeholder={placeholder}
                timezone={timezone}
                onDuplicate={onDuplicate}
                runAs={runAs}
                prevPage={prevPage}
                canManage={canManage}
                canViewLogs={canViewLogs}
                historySupported={historySupported}
              />
            </div>
          </div>

          {/* Two columns, label on top: when and how it runs, then who and whether. */}
          <CardFactGrid>
            <CardFactStacked label={t("columns.schedule")}>
              <CronjobSchedule job={job} presets={schedulePresets} />
            </CardFactStacked>
            {/* The label carries the timezone, like the table's column header. */}
            <CardFactStacked label={timezone ? t("columns.nextRunIn", { timezone }) : t("columns.nextRun")}>
              <CronjobNextRun job={job} />
            </CardFactStacked>
            {historySupported ? (
              <CardFactStacked label={t("lastRun.column")}>
                <CronjobLastRun job={job} />
              </CardFactStacked>
            ) : null}
            <CardFactStacked label={t("columns.runAs")}>
              <CronjobRunAs job={job} />
            </CardFactStacked>
            <CardFactStacked label={t("columns.active")}>
              <CronjobActiveSwitch job={job} canManage={canManage} prevPage={prevPage} />
            </CardFactStacked>
          </CardFactGrid>
        </CardListItem>
      ))}
    </CardList>
  );
}
