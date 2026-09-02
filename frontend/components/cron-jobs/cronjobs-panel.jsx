"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { CalendarClock, SearchX, Plus, Wand2 } from "lucide-react";
import { OTHER_USER } from "@/lib/schemas/cronjob";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { EmptyState } from "@/components/data-table/empty-state";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";
import { useSetQuery } from "@/hooks/use-set-query";
import { CronjobsToolbar } from "@/components/cron-jobs/cronjobs-toolbar";
import { CronjobsTable } from "@/components/cron-jobs/cronjobs-table";
import { CreateCronjobDialog } from "@/components/cron-jobs/create-cronjob-dialog";

// A first job is easier to copy than to compose — offer the two most common
// ones straight from the API's own templates.
const STARTER_KEYS = ["laravel", "wordpress"];

/**
 * Owns the create-dialog state for the whole page so the empty state, the
 * quick starters and the row-level Duplicate can all open the same dialog with
 * different seed values.
 */
export function CronjobsPanel({
  cronjobs,
  meta,
  systemUsers,
  systemUsersFailed = false,
  applications = [],
  canManage,
  schedulePresets,
  commandPresets,
  placeholder,
  timezone,
  isFiltered,
}) {
  const t = useTranslations("cronJobs");
  const setQuery = useSetQuery();
  const [createOpen, setCreateOpen] = useState(false);
  const [seed, setSeed] = useState({ initialValues: undefined, starterKey: undefined });

  function openCreate(next = {}) {
    setSeed({ initialValues: next.initialValues, starterKey: next.starterKey });
    setCreateOpen(true);
  }

  function duplicate(job) {
    openCreate({
      initialValues: {
        // The API rejects a duplicate name, so the copy has to differ.
        name: t("duplicateName", { name: job.name }),
        run_as: job.system_user ? String(job.system_user.id) : OTHER_USER,
        username: job.system_user ? "" : job.username,
        command: job.command,
        expression: job.expression,
        active: job.active,
      },
    });
  }

  const starters = STARTER_KEYS.map((key) =>
    commandPresets.find((p) => p.key === key && p.command),
  ).filter(Boolean);

  // Shown but disabled without permission, not hidden. A page with no buttons
  // reads as broken; a disabled one that says why reads as "not yours".
  const addButton = (
    <ReasonTooltip reason={canManage ? null : t("noPermission")}>
      <Button disabled={!canManage} onClick={() => openCreate()}>
        <Plus className="size-4" />
        {t("addJob")}
      </Button>
    </ReasonTooltip>
  );

  return (
    <>
      <CronjobsToolbar
        systemUsers={systemUsers}
          systemUsersFailed={systemUsersFailed}
        cronjobs={cronjobs}
        canManage={canManage}
        onCreate={() => openCreate()}
      />

      {/* `?page=99` never reaches here: the page redirects to the last real
          page before rendering. See lib/tables/redirect-out-of-range. */}
      {cronjobs.length === 0 ? (
        isFiltered ? (
          <EmptyState
            icon={SearchX}
            title={t("empty.filteredTitle")}
            description={t("empty.filteredDesc")}
            action={
              <Button
                variant="outline"
                onClick={() =>
                  setQuery({ user: undefined, active: undefined }, { resetPage: true })
                }
              >
                {t("empty.clearFilters")}
              </Button>
            }
          />
        ) : (
          <EmptyState
            icon={CalendarClock}
            title={t("empty.title")}
            description={t("empty.desc")}
            action={
              <div className="flex flex-col items-center gap-4">
                {addButton}
                {canManage && starters.length ? (
                  <div className="flex flex-col items-center gap-2">
                    <span className="text-xs text-muted-foreground">
                      {t("empty.starters")}
                    </span>
                    <div className="flex flex-wrap justify-center gap-2">
                      {starters.map((p) => (
                        <Button
                          key={p.key}
                          variant="outline"
                          size="sm"
                          onClick={() => openCreate({ starterKey: p.key })}
                        >
                          <Wand2 className="size-3.5" />
                          {p.label}
                        </Button>
                      ))}
                    </div>
                  </div>
                ) : null}
              </div>
            }
          />
        )
      ) : (
        <CronjobsTable
          data={cronjobs}
          canManage={canManage}
          schedulePresets={schedulePresets}
          commandPresets={commandPresets}
          applications={applications}
          placeholder={placeholder}
          timezone={timezone}
          onDuplicate={duplicate}
        />
      )}

      {/* Not behind a row count: the selector hides itself when the list is too
          short to paginate, and gating it on the current page as well is how it
          used to vanish on the very page you needed it. */}
      <DataTablePagination meta={meta} />

      {canManage ? (
        <CreateCronjobDialog
          open={createOpen}
          onOpenChange={setCreateOpen}
          systemUsers={systemUsers}
          systemUsersFailed={systemUsersFailed}
          schedulePresets={schedulePresets}
          commandPresets={commandPresets}
          applications={applications}
          placeholder={placeholder}
          timezone={timezone}
          initialValues={seed.initialValues}
          starterKey={seed.starterKey}
        />
      ) : null}
    </>
  );
}
