"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { CalendarClock, CircleAlert, SearchX, Plus, Wand2 } from "lucide-react";
import { Caution } from "@/components/ui/caution";
import { CopyButton } from "@/components/ui/copy-button";
import { OTHER_USER } from "@/lib/schemas/cronjob";
import { serverTimeToEpoch } from "@/lib/cron-jobs/schedule";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { EmptyState } from "@/components/data-table/empty-state";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";
import { ListCard } from "@/components/data-table/list-card";
import { useSetQuery } from "@/hooks/use-set-query";
import { CronjobsToolbar } from "@/components/cron-jobs/cronjobs-toolbar";
import { CronjobsTable } from "@/components/cron-jobs/cronjobs-table";
import { CreateCronjobDialog } from "@/components/cron-jobs/create-cronjob-dialog";

// Starter templates offered on the empty state, from the API's own templates.
const STARTER_KEYS = ["laravel", "wordpress"];

// Owns the create dialog so the empty state, quick starters and Duplicate can
// open it with different seed values.
// The command to start it again; a command, so not translated.
const CRON_START = "sudo systemctl start cron";

export function CronjobsPanel({
  cronjobs,
  meta,
  systemUsers,
  systemUsersFailed = false,
  applications = [],
  canManage,
  canViewLogs = false,
  schedulePresets,
  commandPresets,
  placeholder,
  timezone,
  isFiltered,
}) {
  const t = useTranslations("cronJobs");
  const setQuery = useSetQuery();
  const router = useRouter();

  // Re-read the list just after the soonest job is due so its next run moves
  // on. One timer for the page, not a poll.
  useEffect(() => {
    const due = cronjobs
      .map((job) => (job.active ? serverTimeToEpoch(job.next_run_at, job.timezone) : null))
      .filter((epoch) => epoch !== null);
    if (due.length === 0) return undefined;
    const wait = Math.min(Math.max(Math.min(...due) - Date.now() + 3000, 5000), 6 * 3600 * 1000);
    const id = setTimeout(() => router.refresh(), wait);
    return () => clearTimeout(id);
  }, [cronjobs, router]);
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

  // Disabled with a reason rather than hidden when permission is missing.
  const addButton = (
    <ReasonTooltip reason={canManage ? null : t("noPermission")}>
      <Button disabled={!canManage} onClick={() => openCreate()}>
        <Plus className="size-4" />
        {t("addJob")}
      </Button>
    </ReasonTooltip>
  );

  const toolbar = (
    <CronjobsToolbar
      systemUsers={systemUsers}
      cronjobs={cronjobs}
      usernames={meta?.usernames ?? null}
      canManage={canManage}
      onCreate={() => openCreate()}
    />
  );

  return (
    <>
      {/* Cron itself is down: no job runs, the panel's own scheduler included, whatever
          "next run" says. Only on a definite false; null means the server could not tell. */}
      {meta?.cron_running === false ? (
        <Caution tone="destructive" size="md" icon={CircleAlert} className="mb-6">
          <div role="alert" className="space-y-1">
            <p className="font-medium">{t("cronDown.title")}</p>
            <p className="text-muted-foreground">{t("cronDown.body")}</p>
            <p className="flex items-center gap-1 pt-1">
              <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-xs">{CRON_START}</code>
              <CopyButton value={CRON_START} />
            </p>
          </div>
        </Caution>
      ) : null}

      {/* `?page=99` never reaches here: the page redirects to the last real
          page before rendering. See lib/tables/redirect-out-of-range. */}
      {cronjobs.length === 0 ? (
        <ListCard toolbar={toolbar}>
          {isFiltered ? (
            <EmptyState
              icon={SearchX}
              subject={CalendarClock}
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
          )}
        </ListCard>
      ) : (
        // Below xl the rows are cards of their own, so the list drops its frame there.
        <ListCard from="xl" toolbar={toolbar} footer={<DataTablePagination meta={meta} />}>
          <CronjobsTable
            data={cronjobs}
            canViewLogs={canViewLogs}
            runAs={{ users: systemUsers, failed: systemUsersFailed }}
            // The page to land on when this page's only row leaves it (deleted,
            // or switched out of the status filter); null when a refresh will do.
            prevPage={cronjobs.length === 1 && meta.current_page > 1 ? meta.current_page - 1 : null}
            canManage={canManage}
            schedulePresets={schedulePresets}
            commandPresets={commandPresets}
            applications={applications}
            placeholder={placeholder}
            timezone={timezone}
            onDuplicate={duplicate}
          />
        </ListCard>
      )}

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
