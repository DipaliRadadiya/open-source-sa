import { useTranslations } from "next-intl";
import { CardFact, CardFacts, CardList, CardListItem } from "@/components/data-table/card-list";
import { CronjobActiveSwitch } from "@/components/cron-jobs/cronjob-active-switch";
import { CronjobRowActions } from "@/components/cron-jobs/cronjob-row-actions";
import {
  CronjobName,
  CronjobNextRun,
  CronjobRunAs,
  CronjobSchedule,
} from "@/components/cron-jobs/cronjobs-table";

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
              />
            </div>
          </div>

          <CardFacts>
            <CardFact label={t("columns.schedule")}>
              <CronjobSchedule job={job} presets={schedulePresets} />
            </CardFact>
            {/* The label carries the timezone, like the table's column header. */}
            <CardFact
              label={timezone ? t("columns.nextRunIn", { timezone }) : t("columns.nextRun")}
            >
              <CronjobNextRun job={job} />
            </CardFact>
            <CardFact label={t("columns.runAs")}>
              {/* The value is a flex row, which text-align cannot move. */}
              <div className="flex justify-end">
                <CronjobRunAs job={job} />
              </div>
            </CardFact>
            <CardFact label={t("columns.active")}>
              <CronjobActiveSwitch job={job} canManage={canManage} prevPage={prevPage} />
            </CardFact>
          </CardFacts>
        </CardListItem>
      ))}
    </CardList>
  );
}
