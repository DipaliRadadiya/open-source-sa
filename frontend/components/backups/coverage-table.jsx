"use client";

import Link from "next/link";
import { useTranslations } from "next-intl";
import { History, MoreHorizontal, PlayCircle, Settings2, ShieldCheck } from "lucide-react";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { DataTable } from "@/components/ui/data-table";
import { COVERAGE_STATE } from "@/components/backups/status-meta";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";

/* ---------------------------------------------------------------------------
 * One list of every site, sorted worst-first.
 *
 * No row tinting: eight pink rows plus eight red badges was the same alarm
 * twice, and when most of the table shouts nothing reads as urgent. The status
 * badge carries the state on its own.
 *
 * Cells are module-level components — flexRender treats a cell function's
 * identity as the component type, so one defined inside render remounts every
 * time the parent renders.
 * ------------------------------------------------------------------------- */

// Failing first — it is the only row that needs someone today; a site that was
// never set up has been unprotected for weeks and can wait a minute longer.
// Then protected. The placeholders below are what make the rest work: an
// unprotected row states its state in every column instead of showing four
// blanks, so it stays legible without needing to be at the top of the list.
const STATE_ORDER = { failing: 0, protected: 1, paused: 2, unknown: 3, unprotected: 4 };

export function sortCoverage(rows) {
  return [...rows].sort(
    (a, b) =>
      STATE_ORDER[a.state] - STATE_ORDER[b.state] ||
      a.application.name.localeCompare(b.application.name),
  );
}

function SiteCell({ row }) {
  const t = useTranslations("backups.coverage");
  const { application } = row.original;

  return (
    <div className="min-w-0">
      <div className="flex min-w-0 items-center gap-2">
        <Link
          prefetch={false}
          href={`/applications/${application.id}/backups`}
          className="truncate font-medium underline-offset-4 hover:underline"
        >
          {application.name}
        </Link>
        {application.is_staging ? (
          <Badge variant="secondary" className="shrink-0 text-xs">
            {t("staging")}
          </Badge>
        ) : null}
      </div>
      <p className="truncate text-xs text-muted-foreground">{application.domain}</p>
    </div>
  );
}

function StatusCell({ row }) {
  const t = useTranslations("backups.coverage");
  const meta = COVERAGE_STATE[row.original.state];
  const Icon = meta.icon;

  return (
    <Badge variant={meta.variant} className="gap-1.5 font-normal">
      <Icon className="size-3" />
      {t(`status.${row.original.state}`)}
    </Badge>
  );
}

/**
 * A site with no schedule states that in every column.
 *
 * These cells used to render blank, which reads as missing data — eight rows
 * of empty cells look like the table failed to load. Muted placeholder text
 * says "this is a state, not an absence", which is the truth, and it costs no
 * extra colour on the row.
 */
function Placeholder({ children }) {
  return <span className="text-sm text-muted-foreground/70">{children}</span>;
}

function TypeCell({ row }) {
  const t = useTranslations("backups.coverage");
  const { target } = row.original;
  if (!target) return <Placeholder>{t("placeholders.type")}</Placeholder>;
  return <span className="text-sm">{target.type_title ?? target.type}</span>;
}

function ScheduleCell({ row }) {
  const t = useTranslations("backups.coverage");
  const { target } = row.original;
  if (!target) return <Placeholder>{t("placeholders.schedule")}</Placeholder>;

  return (
    <div className="min-w-0">
      <p className="truncate text-sm">{target.frequency_title ?? target.frequency}</p>
      <p className="truncate text-xs tabular-nums text-muted-foreground">
        {t("keeps", { count: target.retention_count })}
      </p>
    </div>
  );
}

function StorageCell({ row }) {
  const t = useTranslations("backups.coverage");
  const { target } = row.original;
  if (!target) return <Placeholder>{t("placeholders.storage")}</Placeholder>;
  return (
    <span className="block truncate text-sm text-muted-foreground">
      {target.storage_destination_name ?? t("placeholders.storage")}
    </span>
  );
}

/**
 * When it last ran, how that went, and when it goes again.
 *
 * `last_backup` is the real outcome — before the API carried it this column
 * could only say when a run was *attempted*, which meant a failed backup and a
 * good one looked identical here. `next_run_at_human` comes from the backend
 * too; computing it from a copy of their cron constants was a mistake worth
 * not repeating.
 */
function RunsCell({ row }) {
  const t = useTranslations("backups.coverage");
  const { target, lastBackup } = row.original;
  if (!target) return <Placeholder>{t("placeholders.lastRun")}</Placeholder>;

  return (
    <div className="min-w-0">
      <div className="flex min-w-0 items-center gap-1.5">
        {lastBackup ? (
          <BackupStatusDot
            status={lastBackup.status}
            label={lastBackup.status_title ?? lastBackup.status}
          />
        ) : null}
        {/* The backup's own timestamp is the fallback, not "Never".
            `last_run_at` is written by the runner, and its crash path does not
            write it — so a site whose every run has crashed reported "Never"
            with a red dot beside it: two contradictory claims in one cell, on
            a site that had in fact tried eight minutes ago. If a run exists,
            something has run. */}
        <span className="truncate text-sm tabular-nums">
          {target.last_run_at_human ?? lastBackup?.created_at_human ?? t("neverRunShort")}
        </span>
      </div>
      {/* `is_due` outranks the timestamp — a brand-new target takes its first
          backup on the next tick, so `next_run_at` alone would name tomorrow
          in the very minute the first run starts. */}
      {target.is_due ? (
        <p className="truncate text-xs text-muted-foreground">{t("runsShortly")}</p>
      ) : target.next_run_at_human ? (
        <p className="truncate text-xs tabular-nums text-muted-foreground">
          {t("nextRun", { when: target.next_run_at_human })}
        </p>
      ) : null}
    </div>
  );
}

/**
 * Outcome of the last run, at a glance, without a second badge in the row.
 *
 * Named as well as coloured. It was a six-pixel dot, `aria-hidden`, with no
 * title — so "your last backup failed" was carried entirely by a colour a
 * screen reader never announced and a reader had to already know.
 */
function BackupStatusDot({ status, label }) {
  const tone =
    status === "verified"
      ? "bg-success"
      : status === "failed"
        ? "bg-destructive"
        : "bg-primary";
  return (
    <span
      className={cn("size-1.5 shrink-0 rounded-full", tone)}
      role="img"
      aria-label={label}
      title={label}
    />
  );
}

/**
 * One shape of action per state, so the column has a single rhythm: an
 * unprotected site gets the one thing it needs, a protected one gets its two.
 */
function ActionsCell({ row, table }) {
  const t = useTranslations("backups.coverage");
  const tc = useTranslations("common");
  const { canManage, onSetUp, onBackUpNow, busyId } = table.options.meta;
  const { application, target, state } = row.original;

  if (state === "unprotected") {
    return canManage ? (
      <div className="flex items-center justify-end gap-1">
        <Button size="sm" variant="outline" onClick={() => onSetUp(application.id)}>
          <ShieldCheck className="size-4" />
          {t("setUpShort")}
        </Button>
        {/* Holds the space the ⋯ takes on configured rows. Without it the two
            protected rows sat 36px inset from the eight below them and the
            column had two right edges. */}
        <span className="size-8 shrink-0" aria-hidden />
      </div>
    ) : null;
  }

  // One button plus a menu, not two buttons. Two full-width labels per row for
  // ten rows is twenty competing targets; the thing you came to press stays a
  // button, and the two you might want occasionally move behind the ⋯.
  return (
    <div className="flex items-center justify-end gap-1">
      {canManage ? (
        <ReasonTooltip
          reason={!target && busyId !== application.id ? tc("needsBackupTarget") : null}
        >
          <Button
            size="sm"
            variant="outline"
            onClick={() => onBackUpNow(application.id, application.name)}
            disabled={busyId === application.id || !target}
          >
            <PlayCircle className="size-4" />
            {t("runBackup")}
          </Button>
        </ReasonTooltip>
      ) : null}
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="icon" className="size-8" aria-label={t("moreActions")}>
            <MoreHorizontal className="size-4" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem asChild>
            <Link href={`/backups/history?application=${application.id}`}>
              <History className="size-4" />
              {t("viewBackups")}
            </Link>
          </DropdownMenuItem>
          <DropdownMenuItem asChild>
            <Link href={`/applications/${application.id}/backups`}>
              <Settings2 className="size-4" />
              {t("manage")}
            </Link>
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  );
}

export function CoverageTable({ rows, canManage, onSetUp, onBackUpNow, busyId }) {
  const t = useTranslations("backups.coverage");

  const columns = [
    {
      accessorKey: "application.name",
      header: t("columns.site"),
      meta: { className: "min-w-52" },
      cell: SiteCell,
    },
    { accessorKey: "state", header: t("columns.status"), meta: { className: "w-36" }, cell: StatusCell },
    { id: "type", header: t("columns.type"), meta: { className: "w-44" }, cell: TypeCell },
    { id: "schedule", header: t("columns.schedule"), meta: { className: "w-32" }, cell: ScheduleCell },
    { id: "storage", header: t("columns.storage"), meta: { className: "w-40" }, cell: StorageCell },
    { id: "runs", header: t("columns.lastRun"), meta: { className: "w-40" }, cell: RunsCell },
    {
      id: "actions",
      header: () => <span className="sr-only">{t("columns.actions")}</span>,
      meta: { className: "w-44 text-right" },
      cell: ActionsCell,
    },
  ];

  return (
    <DataTable
      columns={columns}
      data={rows}
      emptyMessage={t("noMatches")}
      // A site with no target has nothing to say in four separate columns. One
      // sentence says it once; four muted placeholders per row turned ten rows
      // into thirty-two pieces of grey text repeating a single fact.
      spanCells={{
        columns: ["type", "schedule", "storage", "runs"],
        render: (row) => (row.target ? null : <NotSetUp />),
      }}
      meta={{ canManage, onSetUp, onBackUpNow, busyId }}
    />
  );
}

function NotSetUp() {
  const t = useTranslations("backups.coverage");
  return <span className="text-sm text-muted-foreground/80">{t("notSetUpLine")}</span>;
}
