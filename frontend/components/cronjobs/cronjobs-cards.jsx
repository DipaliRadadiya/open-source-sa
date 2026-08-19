"use client";

import { useTranslations } from "next-intl";
import { CardFact, CardFacts, CardList, CardListItem } from "@/components/data-table/card-list";
import { CronjobActiveSwitch } from "@/components/cronjobs/cronjob-active-switch";
import { CronjobRowActions } from "@/components/cronjobs/cronjob-row-actions";
import {
  CronjobName,
  CronjobNextRun,
  CronjobRunAs,
  CronjobSchedule,
} from "@/components/cronjobs/cronjobs-table";

/**
 * Cron jobs on a narrow screen.
 *
 * Seven columns fit a phone even worse than six: the table showed the name and
 * half a schedule, so you could not tell whether a job was paused, when it next
 * runs, or reach the menu to fix either.
 *
 * The command gets the wide line under the name — it is what the job actually
 * *is*, and it is the one value long enough to need the room. Everything else
 * is a labelled pair, including the on/off switch: a lone switch on a card has
 * no column header left to say what it toggles.
 */
export function CronjobsCards({
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
              <p className="truncate font-mono text-xs text-muted-foreground">{job.command}</p>
            </div>
            {canManage ? (
              <div className="-me-2 -mt-1 shrink-0">
                <CronjobRowActions
                  job={job}
                  schedulePresets={schedulePresets}
                  commandPresets={commandPresets}
                  applications={applications}
                  placeholder={placeholder}
                  timezone={timezone}
                  onDuplicate={onDuplicate}
                />
              </div>
            ) : null}
          </div>

          <CardFacts>
            <CardFact label={t("columns.schedule")}>
              <CronjobSchedule job={job} presets={schedulePresets} />
            </CardFact>
            <CardFact label={t("columns.nextRun")}>
              <CronjobNextRun job={job} />
            </CardFact>
            <CardFact label={t("columns.runAs")}>
              {/* The value is a flex row, which text-align cannot move — it needs
                  pushing to the card's right edge itself. */}
              <div className="flex justify-end">
                <CronjobRunAs job={job} />
              </div>
            </CardFact>
            <CardFact label={t("columns.active")}>
              <CronjobActiveSwitch job={job} canManage={canManage} />
            </CardFact>
          </CardFacts>
        </CardListItem>
      ))}
    </CardList>
  );
}
