"use client";

import { useEffect, useState } from "react";
import Link from "@/components/ui/app-link";
import { toast } from "sonner";
import { useFormatter, useTranslations } from "next-intl";
import { History, MoreHorizontal, PlayCircle, Settings2, ShieldCheck } from "lucide-react";
import { usePendingKeys } from "@/hooks/use-pending-keys";
import { useRefresh } from "@/hooks/use-refresh";
import { runDatabaseBackupNow } from "@/lib/api/database-backups";
import { apiMessage } from "@/lib/api/error-message";
import { BACKUP_IN_FLIGHT } from "@/lib/schemas/backup";
import { scheduleWhen } from "@/lib/backups/schedule-time";
import { frequencyLabel } from "@/lib/backups/frequency";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { ActionIcon } from "@/components/ui/action-icon";
import { AutoRefresh } from "@/components/ui/auto-refresh";
import { DataTable } from "@/components/ui/data-table";
import { HoverHint } from "@/components/ui/hover-hint";
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from "@/components/ui/dropdown-menu";
import { ListCard } from "@/components/data-table/list-card";
import { LoadFailed } from "@/components/data-table/load-failed";
import { CardFact, CardFacts, CardList, CardListItem } from "@/components/data-table/card-list";
import { BackupStatusBadge } from "@/components/backups/backup-status-badge";
import { RunsCell, ScheduleCell, StatusCell, StorageCell } from "@/components/backups/coverage-table";

const JUST_STARTED_MS = 90_000;

/**
 * Databases that are not part of an application (RC-23), under the applications'
 * coverage: a site's database is backed up with the site, these have schedules of
 * their own, set on the database's Backups tab. Same columns and widths as the
 * applications table above, so the two line up (Krishna, 4 Nov).
 */
export function DatabaseCoverageCard({ coverage, options, canOpenDatabases = false, canManage = false }) {
  const t = useTranslations("backups.databases");
  const tc = useTranslations("backups.coverage");
  const { refreshAndWait } = useRefresh();
  const starting = usePendingKeys();
  const [justStarted, setJustStarted] = useState(false);

  useEffect(() => {
    if (!justStarted) return undefined;
    const id = setTimeout(() => setJustStarted(false), JUST_STARTED_MS);
    return () => clearTimeout(id);
  }, [justStarted]);

  // Nothing to say when every database belongs to a site.
  if (!coverage.failed && coverage.rows.length === 0) return null;

  async function backUpNow(databaseId, name) {
    if (starting.isPending(databaseId)) return;
    starting.start(databaseId);
    try {
      await runDatabaseBackupNow(databaseId);
      setJustStarted(true);
      await refreshAndWait();
      toast.success(tc("started", { name }));
    } catch (error) {
      toast.error(apiMessage(error, tc("startFailed")));
    } finally {
      starting.finish(databaseId);
    }
  }

  const watching = justStarted || coverage.rows.some((row) => BACKUP_IN_FLIGHT.includes(row.lastBackup?.status));
  const listProps = { rows: coverage.rows, options, canOpenDatabases, canManage, onBackUpNow: backUpNow, busyIds: starting.pendingKeys };

  const heading = (
    <div className="min-w-0 px-1">
      <h2 className="flex items-center gap-2 text-[15px] font-semibold tracking-tight">
        {t("title")}
        {!coverage.failed ? <span className="text-xs font-normal tabular-nums text-muted-foreground">{coverage.total}</span> : null}
      </h2>
      <p className="text-sm text-muted-foreground">{t("description")}</p>
    </div>
  );

  if (coverage.failed) {
    return (
      <ListCard toolbar={heading}>
        <div className="p-5">
          <LoadFailed description={t("loadFailed")} status={coverage.status} failure={coverage.failure} message={coverage.message} />
        </div>
      </ListCard>
    );
  }

  return (
    <>
      {watching ? <AutoRefresh intervalMs={5000} stopAfterMs={600000} /> : null}
      {/* The applications list's switch point, so both turn into cards together. */}
      <ListCard from="c900" toolbar={heading}>
        <div className="@min-[900px]:hidden">
          <DatabaseCards {...listProps} />
        </div>
        <div className="hidden @min-[900px]:block">
          <DatabaseTable {...listProps} />
        </div>
      </ListCard>
    </>
  );
}

function useEngineName() {
  const t = useTranslations("databases");
  return (engine) => (engine && t.has(`engines.${engine}`) ? t(`engines.${engine}`) : engine);
}

function NameCell({ row, table }) {
  const { database, state } = row.original;
  const { canOpenDatabases } = table.options.meta;
  return (
    <div className="flex min-w-0 items-center gap-2">
      <HoverHint content={database.name}>
        {canOpenDatabases ? (
          <Link prefetch={false} href={`/databases/${database.id}?tab=backups`} className="truncate font-medium underline-offset-4 hover:underline">
            {database.name}
          </Link>
        ) : (
          <span className="truncate font-medium">{database.name}</span>
        )}
      </HoverHint>
      {/* As on the application rows: no badge where the row already says it. */}
      {state === "paused" || state === "unprotected" ? null : (
        <span className="shrink-0">
          <StatusCell row={row} />
        </span>
      )}
    </div>
  );
}

function EngineCell({ row }) {
  const engineName = useEngineName();
  return <span className="block truncate text-sm">{engineName(row.original.database.engine) ?? "—"}</span>;
}

function ActionsCell({ row, table }) {
  const { canOpenDatabases, canManage, onBackUpNow, busyIds } = table.options.meta;
  return <DatabaseActions row={row.original} canOpenDatabases={canOpenDatabases} canManage={canManage} onBackUpNow={onBackUpNow} busy={busyIds.includes(row.original.database.id)} />;
}

function DatabaseActions({ row, canOpenDatabases, canManage, onBackUpNow, busy, stacked = false }) {
  const tc = useTranslations("backups.coverage");
  const td = useTranslations("databases");
  const { database, target } = row;
  const settings = `/databases/${database.id}?tab=backups`;

  // Not set up: the schedule is made on the database's own Backups tab.
  if (!target) {
    return (
      <div className="flex items-center justify-end gap-1">
        {canOpenDatabases ? (
          <Button asChild size="sm" variant="outline">
            <Link href={settings} prefetch={false}>
              <ShieldCheck className="size-4" />
              {tc("setUpShort")}
            </Link>
          </Button>
        ) : null}
        {stacked ? null : <span className="size-8 shrink-0" aria-hidden />}
      </div>
    );
  }

  const run = (
    <ReasonTooltip reason={canManage ? null : td("noPermission")}>
      <Button size="sm" variant="outline" disabled={!canManage || busy} onClick={() => onBackUpNow(database.id, database.name)}>
        <ActionIcon icon={PlayCircle} pending={busy} className="size-4" />
        {tc("runBackup")}
      </Button>
    </ReasonTooltip>
  );

  if (stacked) {
    return (
      <div className="mt-auto flex flex-wrap justify-end gap-2">
        {run}
        <Button size="sm" variant="ghost" asChild>
          <Link href={`/backups/history?database=${database.id}`} prefetch={false}>
            <History className="size-4" />
            {tc("viewBackups")}
          </Link>
        </Button>
        {canOpenDatabases ? (
          <Button size="sm" variant="ghost" asChild>
            <Link href={settings} prefetch={false}>
              <Settings2 className="size-4" />
              {tc("manage")}
            </Link>
          </Button>
        ) : null}
      </div>
    );
  }

  return (
    <div className="flex items-center justify-end gap-1">
      {run}
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="icon" className="size-8" aria-label={tc("moreActions")}>
            <MoreHorizontal className="size-4" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem asChild>
            <Link href={`/backups/history?database=${database.id}`}>
              <History className="size-4" />
              {tc("viewBackups")}
            </Link>
          </DropdownMenuItem>
          {canOpenDatabases ? (
            <DropdownMenuItem asChild>
              <Link href={settings}>
                <Settings2 className="size-4" />
                {tc("manage")}
              </Link>
            </DropdownMenuItem>
          ) : null}
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  );
}

const wrapping = (label) => function WrappingHeader() {
  return <span className="whitespace-normal">{label}</span>;
};

// Widths copied from CoverageTable, column for column.
function DatabaseTable({ rows, options, canOpenDatabases, canManage, onBackUpNow, busyIds }) {
  const t = useTranslations("backups.databases");
  const tc = useTranslations("backups.coverage");
  const columns = [
    { id: "database", header: t("columns.database"), meta: { className: "w-[28%] max-w-0" }, cell: NameCell },
    { id: "engine", header: wrapping(t("columns.engine")), meta: { className: "w-28" }, cell: EngineCell },
    { id: "schedule", header: wrapping(tc("columns.schedule")), meta: { className: "w-32" }, cell: (ctx) => <ScheduleCell {...ctx} options={options} /> },
    { id: "storage", header: wrapping(tc("columns.storage")), meta: { className: "w-32" }, cell: StorageCell },
    { id: "runs", header: wrapping(tc("columns.lastRun")), meta: { className: "w-36" }, cell: RunsCell },
    { id: "actions", header: () => <span className="sr-only">{tc("columns.actions")}</span>, meta: { className: "w-44 text-right" }, cell: ActionsCell },
  ];

  return (
    <DataTable
      bare
      columns={columns}
      data={rows}
      rowId={(row) => String(row.database.id)}
      // A database with no schedule gets one sentence across the three columns.
      spanCells={{
        columns: ["schedule", "storage", "runs"],
        render: (row) => (row.target ? null : <span className="text-sm whitespace-normal text-muted-foreground/80">{t("notSetUpLine")}</span>),
      }}
      meta={{ canOpenDatabases, canManage, onBackUpNow, busyIds }}
    />
  );
}

function DatabaseCards({ rows, options, canOpenDatabases, canManage, onBackUpNow, busyIds }) {
  const t = useTranslations("backups.databases");
  const tc = useTranslations("backups.coverage");
  const tb = useTranslations("backups");
  const format = useFormatter();
  const engineName = useEngineName();

  return (
    <CardList>
      {rows.map((row) => {
        const { database, target, state, lastBackup } = row;
        const when = target ? scheduleWhen(target, options, format) : null;
        const next = target?.is_due ? tc("runsShortly") : target?.next_run_at_human ? tc("nextRun", { when: target.next_run_at_human }) : null;
        const busy = busyIds.includes(database.id);

        return (
          <CardListItem key={database.id}>
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                {canOpenDatabases ? (
                  <Link prefetch={false} href={`/databases/${database.id}?tab=backups`} className="block truncate font-medium underline-offset-4 hover:underline">
                    {database.name}
                  </Link>
                ) : (
                  <span className="block truncate font-medium">{database.name}</span>
                )}
                {database.engine ? <span className="block text-xs text-muted-foreground">{engineName(database.engine)}</span> : null}
              </div>
              {state === "paused" || state === "unprotected" ? null : (
                <span className="shrink-0">
                  <StatusCell row={{ original: row }} />
                </span>
              )}
            </div>

            {!target ? (
              <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t pt-3">
                <p className="min-w-48 flex-1 text-sm text-muted-foreground">{t("notSetUpLine")}</p>
                <DatabaseActions row={row} canOpenDatabases={canOpenDatabases} canManage={canManage} onBackUpNow={onBackUpNow} busy={busy} stacked />
              </div>
            ) : (
              <>
                <CardFacts>
                  <CardFact
                    label={tc("columns.schedule")}
                    className={state === "paused" ? "[&>dd]:font-medium [&>dd]:text-warning" : undefined}
                    value={[
                      frequencyLabel(target, (frequency) => tb("pausedFrequency", { frequency })),
                      when?.minute ? tc("minutePast", { minute: when.minute }) : when?.time,
                      tc("keeps", { count: target.retention_count }),
                    ].filter(Boolean).join(" · ")}
                  />
                  <CardFact label={tc("columns.storage")} value={target.storage_destination_name ?? tc("placeholders.storage")} />
                  <CardFact label={tc("columns.lastRun")}>
                    {lastBackup && (BACKUP_IN_FLIGHT.includes(lastBackup.status) || lastBackup.status === "failed") ? (
                      <span className="mb-1 block">
                        <BackupStatusBadge backup={lastBackup} />
                      </span>
                    ) : null}
                    <span className="block truncate">{lastBackup?.created_at_human ?? target.last_backup_at_human ?? tc("neverRunShort")}</span>
                    {next ? <span className="block truncate text-xs text-muted-foreground">{next}</span> : null}
                  </CardFact>
                </CardFacts>
                <DatabaseActions row={row} canOpenDatabases={canOpenDatabases} canManage={canManage} onBackUpNow={onBackUpNow} busy={busy} stacked />
              </>
            )}
          </CardListItem>
        );
      })}
    </CardList>
  );
}
